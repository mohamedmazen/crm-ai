<?php

namespace App\Modules\AI\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Services\StorageManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AiKnowledgeBaseController extends Controller
{
    public function __construct(
        private StorageManager $storage,
        private EmbeddingStore $embeddings,
    ) {}

    public function index(Request $request): Response
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $kbs = AiKnowledgeBase::where('workspace_id', $workspaceId)
            ->withCount('documents')
            ->latest()->get();

        return Inertia::render('AI/KnowledgeBases/Index', [
            'knowledgeBases' => $kbs,
            'hasEmbeddingProvider' => $this->hasEmbeddingProvider($workspaceId),
        ]);
    }

    public function show(Request $request, AiKnowledgeBase $kb): Response
    {
        $this->authorise($request, $kb);
        $kb->load('documents');

        return Inertia::render('AI/KnowledgeBases/Show', [
            'kb' => $kb,
            'hasEmbeddingProvider' => $this->hasEmbeddingProvider((int) $kb->workspace_id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        $validated = $request->validate(['name' => ['required', 'string', 'max:128']]);
        AiKnowledgeBase::create(array_merge($validated, ['workspace_id' => $workspaceId]));

        return back()->with('success', 'Knowledge base created.');
    }

    /**
     * Usage report for one knowledge base: index size plus chatbot traffic for
     * the last 30 days, broken down per day for the last 14.
     */
    public function analytics(Request $request, AiKnowledgeBase $kb): Response
    {
        $this->authorise($request, $kb);

        $workspaceId = (int) $kb->workspace_id;

        $chatbotIds = AiChatbot::where('workspace_id', $workspaceId)
            ->where(function ($query) use ($kb): void {
                $query->where('ai_kb_id', $kb->id)
                    ->orWhereHas(
                        'knowledgeBases',
                        fn ($relation) => $relation->where('ai_knowledge_bases.id', $kb->id),
                    );
            })
            ->pluck('id');

        $runs = AiRun::where('workspace_id', $workspaceId)
            ->whereIn('chatbot_id', $chatbotIds)
            ->where('created_at', '>=', now()->subDays(30)->startOfDay())
            ->get(['status', 'prompt_tokens', 'completion_tokens', 'created_at', 'context_chunk_ids']);

        $byDay = $runs->groupBy(fn (AiRun $run) => $run->created_at->toDateString());

        $daily = collect(range(13, 0))->map(function (int $daysAgo) use ($byDay): array {
            $date = now()->subDays($daysAgo)->toDateString();
            $dayRuns = $byDay->get($date, collect());

            return [
                'date' => $date,
                'queries' => $dayRuns->count(),
                'tokens' => $dayRuns->sum(fn (AiRun $run) => $run->prompt_tokens + $run->completion_tokens),
                'errors' => $dayRuns->where('status', '!=', 'ok')->count(),
            ];
        })->values()->all();

        $queries = $runs->count();
        $errors = $runs->where('status', '!=', 'ok')->count();

        return Inertia::render('AI/KnowledgeBases/Analytics', [
            'kb' => [
                'uuid' => $kb->uuid,
                'name' => $kb->name,
                'status' => $kb->status,
                'embedding_model' => $kb->embedding_model,
                'dimensions' => $kb->dimensions,
            ],
            'stats' => [
                'documents' => $kb->documents()->count(),
                'chunks' => AiKbChunk::where('kb_id', $kb->id)->count(),
                'tokens' => (int) AiKbChunk::where('kb_id', $kb->id)->sum('tokens'),
                'chatbots' => $chatbotIds->count(),
                'queries_30d' => $queries,
                'tokens_30d' => (int) $runs->sum(fn (AiRun $run) => $run->prompt_tokens + $run->completion_tokens),
                'error_rate_30d' => $queries > 0 ? round($errors / $queries * 100, 1) : 0.0,
                'last_indexed_at' => AiKbDocument::where('kb_id', $kb->id)->max('last_indexed_at'),
            ],
            'daily' => $daily,
            'topChunks' => $this->topChunks($runs),
        ]);
    }

    /**
     * Preview of one indexed document: metadata plus the chunks that were
     * embedded from it, with how often each was retrieved in the last 30 days.
     */
    public function document(Request $request, AiKnowledgeBase $kb, AiKbDocument $document): Response
    {
        $this->authorise($request, $kb);

        abort_unless((int) $document->kb_id === (int) $kb->id, 404);

        $workspaceId = (int) $kb->workspace_id;

        $chunks = $document->chunks()
            ->orderBy('ord')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (AiKbChunk $chunk) => [
                'id' => $chunk->id,
                'ord' => $chunk->ord,
                'content' => $chunk->content,
                'tokens' => $chunk->tokens,
                'heading' => $chunk->meta['heading'] ?? null,
                'embedded' => filled($chunk->embedding),
                'used_30d' => 0,
            ]);

        $usage = $this->chunkUsage($workspaceId, $chunks->pluck('id')->all());

        $chunks->through(function (array $chunk) use ($usage): array {
            $chunk['used_30d'] = $usage[$chunk['id']] ?? 0;

            return $chunk;
        });

        return Inertia::render('AI/KnowledgeBases/Document', [
            'kb' => [
                'uuid' => $kb->uuid,
                'name' => $kb->name,
                'embedding_model' => $kb->embedding_model,
                'dimensions' => $kb->dimensions,
            ],
            'document' => [
                'uuid' => $document->uuid,
                'title' => $document->title,
                'source_type' => $document->source_type,
                'source_ref' => $document->source_ref,
                'status' => $document->status,
                'tokens' => (int) $document->tokens,
                'last_indexed_at' => $document->last_indexed_at,
                'chunk_count' => $document->chunks()->count(),
            ],
            'chunks' => $chunks,
        ]);
    }

    /**
     * Top retrieved chunks across the runs supplied.
     *
     * Runs store the ids of the chunks injected into their prompt, so the
     * analytics page can show which passages actually answer questions (and
     * which are dead weight in the index).
     *
     * @param  Collection<int, AiRun>  $runs
     * @return array<int, array{id: int, content: string, heading: ?string, document: ?string, document_uuid: ?string, uses: int}>
     */
    private function topChunks($runs, int $limit = 10): array
    {
        $usage = [];

        foreach ($runs as $run) {
            foreach ($run->context_chunk_ids ?? [] as $chunkId) {
                $chunkId = (int) $chunkId;
                $usage[$chunkId] = ($usage[$chunkId] ?? 0) + 1;
            }
        }

        if ($usage === []) {
            return [];
        }

        arsort($usage);
        $topIds = array_slice(array_keys($usage), 0, $limit);

        return AiKbChunk::whereIn('id', $topIds)
            ->with('document:id,uuid,title')
            ->get()
            ->map(fn (AiKbChunk $chunk) => [
                'id' => $chunk->id,
                'content' => mb_strimwidth((string) $chunk->content, 0, 220, '…'),
                'heading' => $chunk->meta['heading'] ?? null,
                'document' => $chunk->document?->title,
                'document_uuid' => $chunk->document?->uuid,
                'uses' => $usage[$chunk->id] ?? 0,
            ])
            ->sortByDesc('uses')
            ->values()
            ->all();
    }

    /**
     * How often each chunk appears in the context of a recent run.
     *
     * @param  list<int>  $chunkIds
     * @return array<int, int> chunk id => uses
     */
    private function chunkUsage(int $workspaceId, array $chunkIds, int $days = 30): array
    {
        if ($chunkIds === []) {
            return [];
        }

        $targets = array_flip(array_map('intval', $chunkIds));
        $usage = [];

        AiRun::where('workspace_id', $workspaceId)
            ->where('created_at', '>=', now()->subDays($days)->startOfDay())
            ->whereNotNull('context_chunk_ids')
            ->select(['id', 'context_chunk_ids'])
            ->orderBy('id')
            ->chunk(500, function ($runs) use ($targets, &$usage): void {
                foreach ($runs as $run) {
                    foreach ($run->context_chunk_ids ?? [] as $chunkId) {
                        $chunkId = (int) $chunkId;

                        if (isset($targets[$chunkId])) {
                            $usage[$chunkId] = ($usage[$chunkId] ?? 0) + 1;
                        }
                    }
                }
            });

        return $usage;
    }

    public function addDocument(Request $request, AiKnowledgeBase $kb): RedirectResponse
    {
        $this->authorise($request, $kb);

        $validated = $request->validate([
            'source_type' => ['required', 'in:file,url,text,sitemap,faq'],
            'source_ref' => ['nullable', 'string', 'max:512'],
            'title' => ['nullable', 'string', 'max:256'],
        ]);

        // Handle file upload
        if ($request->hasFile('file')) {
            $request->validate([
                'file' => [
                    'file',
                    'max:20480',
                    'mimes:pdf,txt,md,csv,docx,doc,xlsx,xls,json',
                ],
            ]);
            $file = $request->file('file');
            $diskName = $this->storage->diskName();
            $path = $this->storage->prefixedPath('kb-docs/'.$file->hashName());
            $this->storage->disk()->putFileAs(dirname($path), $file, basename($path));
            $validated['source_ref'] = $path;
            $validated['title'] = $validated['title'] ?? $file->getClientOriginalName();
        } elseif (! empty($validated['source_ref']) && $this->documentExists($kb, $validated['source_type'], $validated['source_ref'])) {
            // Re-adding the same URL/text/FAQ would double every chunk in the
            // index, so retrieval would return the same passage twice and
            // waste context tokens (and embedding calls on re-index).
            return back()->withErrors([
                'source_ref' => 'This source is already in the knowledge base.',
            ]);
        }

        $doc = AiKbDocument::create(array_merge($validated, ['kb_id' => $kb->id, 'status' => 'pending']));

        try {
            IndexDocumentJob::dispatch($doc->id, (int) $kb->workspace_id)->onQueue('ai');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['source_type' => $e->getMessage()]);
        }

        return back()->with('success', 'Document queued for indexing.');
    }

    public function reindex(Request $request, AiKbDocument $document): RedirectResponse
    {
        $kb = $document->load('knowledgeBase')->knowledgeBase;
        $this->authorise($request, $kb);
        $document->update(['status' => 'pending']);

        try {
            IndexDocumentJob::dispatch($document->id, (int) $kb->workspace_id)->onQueue('ai');
        } catch (\RuntimeException $e) {
            return back()->withErrors(['document' => $e->getMessage()]);
        }

        return back()->with('success', 'Re-indexing queued.');
    }

    public function destroyDocument(Request $request, AiKbDocument $document): RedirectResponse
    {
        $kb = $document->load('knowledgeBase')->knowledgeBase;
        $this->authorise($request, $kb);
        $this->embeddings->deleteForDocument($document->id);
        $document->chunks()->delete();
        $document->delete();

        return back()->with('success', 'Document removed.');
    }

    private function authorise(Request $request, AiKnowledgeBase $kb): void
    {
        $workspaceId = $request->user()->current_workspace_id ?? $request->user()->workspace_id;
        abort_unless((int) $kb->workspace_id === (int) $workspaceId, 403);
    }

    private function documentExists(AiKnowledgeBase $kb, string $sourceType, string $sourceRef): bool
    {
        return AiKbDocument::where('kb_id', $kb->id)
            ->where('source_type', $sourceType)
            ->where('source_ref', $sourceRef)
            ->exists();
    }

    /**
     * True when the workspace can produce embeddings (OpenAI or Gemini).
     * Anthropic and OpenRouter are chat-only, so documents would index as plain
     * text with no vectors and semantic search would silently return nothing.
     */
    private function hasEmbeddingProvider(int $workspaceId): bool
    {
        try {
            LlmManager::forWorkspaceEmbed($workspaceId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
