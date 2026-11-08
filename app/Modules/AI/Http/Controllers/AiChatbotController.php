<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiChatbotController extends Controller
{
    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $chatbots = AiChatbot::where('workspace_id', $wid)
            ->with(['knowledgeBase', 'knowledgeBases'])
            ->latest()
            ->get()
            ->each(function (AiChatbot $chatbot): void {
                $chatbot->setAttribute(
                    'knowledge_base_ids',
                    $chatbot->resolvedKnowledgeBases()->pluck('id')->all(),
                );
            });

        $knowledgeBases = AiKnowledgeBase::where('workspace_id', $wid)->get(['id', 'name']);

        return Inertia::render('AI/Chatbots/Index', [
            'chatbots' => $chatbots,
            'knowledgeBases' => $knowledgeBases,
            'streamingEnabled' => (bool) config('ai.streaming.enabled', true),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
        ]);

        AiChatbot::create(array_merge($validated, ['workspace_id' => $wid]));

        return back()->with('success', 'Chatbot created.');
    }

    public function update(Request $request, AiChatbot $chatbot): RedirectResponse
    {
        $this->authorise($request, $chatbot);
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'ai_kb_id' => ['nullable', 'integer'],
            'knowledge_base_ids' => ['nullable', 'array'],
            'knowledge_base_ids.*' => ['integer'],
            'system_prompt' => ['nullable', 'string', 'max:8192'],
            'tone' => ['nullable', 'string', 'max:64'],
            'max_context_chunks' => ['nullable', 'integer', 'min:1', 'max:20'],
            'fallback_reply' => ['nullable', 'string', 'max:512'],
            'channels' => ['nullable', 'array'],
            'enabled' => ['boolean'],
        ]);

        // A multi-select sends knowledge_base_ids; older clients and the API
        // still send the single ai_kb_id, which becomes a one-element set.
        $kbIds = $request->has('knowledge_base_ids')
            ? collect($request->input('knowledge_base_ids', []))
            : collect([$validated['ai_kb_id'] ?? null]);

        $kbIds = $kbIds
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($kbIds->isNotEmpty()) {
            $owned = AiKnowledgeBase::where('workspace_id', $wid)
                ->whereIn('id', $kbIds)
                ->pluck('id');

            // Refuse the whole update rather than silently dropping a base the
            // caller does not own.
            abort_unless($owned->count() === $kbIds->count(), 422);
        }

        unset($validated['knowledge_base_ids']);

        // Keep the legacy column pointing at the first base so single-base
        // consumers (old API responses, exports) keep working.
        $validated['ai_kb_id'] = $kbIds->first();

        $chatbot->update($validated);
        $chatbot->knowledgeBases()->syncWithPivotValues($kbIds->all(), ['workspace_id' => $wid]);

        return back()->with('success', 'Chatbot updated.');
    }

    public function destroy(Request $request, AiChatbot $chatbot): RedirectResponse
    {
        $this->authorise($request, $chatbot);
        $chatbot->delete();

        return back()->with('success', 'Chatbot deleted.');
    }

    public function playground(Request $request, AiChatbot $chatbot): JsonResponse
    {
        $this->authorise($request, $chatbot);
        $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array'],
        ]);

        $wid = $this->workspaceId($request);

        try {
            // Build a synthetic inbound Message model (unsaved) for ChatbotRunner
            $fakeMessage = new Message;
            $fakeMessage->body = $request->message;
            $fakeMessage->direction = 'in';
            $fakeMessage->channel = 'playground';

            // Attach a minimal conversation with workspace context
            $fakeConversation = new Conversation;
            $fakeConversation->workspace_id = $this->workspaceId($request);
            $fakeConversation->id = 0;
            $fakeMessage->setRelation('conversation', $fakeConversation);

            $reply = app(ChatbotRunner::class)->run($chatbot, $fakeMessage);

            return response()->json([
                'reply' => $reply ?? $chatbot->fallback_reply ?? 'No response.',
            ]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Streaming playground: the same grounded answer as {@see playground()},
     * but delivered as server-sent events so the reply appears as it is
     * written. The webhook path is untouched.
     */
    public function playgroundStream(Request $request, AiChatbot $chatbot): StreamedResponse
    {
        $this->authorise($request, $chatbot);

        abort_unless((bool) config('ai.streaming.enabled', true), 404);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:1000'],
            'history' => ['nullable', 'array'],
        ]);

        $wid = $this->workspaceId($request);
        $message = $validated['message'];
        $history = $this->normaliseHistory($validated['history'] ?? []);

        return response()->stream(function () use ($chatbot, $message, $wid, $history): void {
            // Long-running streaming responses must not be buffered by PHP or
            // the reverse proxy (Herd/nginx) or deltas arrive in one burst.
            // Do not tear down existing buffers: the test harness and Laravel
            // own those, and nginx reads `X-Accel-Buffering` instead.
            @ini_set('zlib.output_compression', '0');
            @ini_set('implicit_flush', '1');
            ob_implicit_flush(true);

            try {
                foreach (app(ChatbotRunner::class)->streamForApi($chatbot, $message, $wid, $history) as $delta) {
                    $this->sendEvent(['delta' => $delta]);

                    flush();
                }
            } catch (\Throwable $e) {
                $this->sendEvent(['error' => $e->getMessage()]);
            }

            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-store',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * Emit one server-sent event.
     *
     * @param  array<string, mixed>  $payload
     */
    private function sendEvent(array $payload): void
    {
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";
    }

    /**
     * Keep only well-formed, bounded turns from the client-supplied history.
     *
     * @param  array<int, mixed>  $history
     * @return list<array{role: string, content: string}>
     */
    private function normaliseHistory(array $history): array
    {
        $turns = [];

        foreach (array_slice($history, -20) as $turn) {
            if (! is_array($turn)) {
                continue;
            }

            $role = $turn['role'] ?? null;
            $content = $turn['content'] ?? null;

            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content) || trim($content) === '') {
                continue;
            }

            $turns[] = ['role' => $role, 'content' => mb_substr($content, 0, 4000)];
        }

        return $turns;
    }

    private function authorise(Request $request, AiChatbot $chatbot): void
    {
        abort_unless((int) $chatbot->workspace_id === $this->workspaceId($request), 403);
    }
}
