<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\Llm\SupportsStreaming;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Support\Facades\Log;

class LlmGateway
{
    /**
     * @param  array  $messages  [['role' => 'user', 'content' => '...'], ...]
     * @param  array  $opts  Provider options (max_tokens, temperature, system, ...)
     * @param  string|null  $feature  Which product feature issued the call, for the run ledger
     * @param  list<int>  $contextChunkIds  Knowledge-base chunks injected into the prompt
     */
    public function chat(
        int $workspaceId,
        array $messages,
        array $opts = [],
        ?int $chatbotId = null,
        ?int $conversationId = null,
        ?string $feature = null,
        array $contextChunkIds = [],
    ): LlmResponse {
        $provider = LlmManager::forWorkspace($workspaceId);
        $response = $provider->chat($messages, $opts);

        $totalTokens = $response->promptTokens + $response->completionTokens;
        UsageMeter::track($workspaceId, 'ai_tokens', $totalTokens);

        $this->record($workspaceId, $feature, [
            'chatbot_id' => $chatbotId,
            'conversation_id' => $conversationId,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'cost_cents' => $this->costCents($response->model, $response->promptTokens, $response->completionTokens),
            'latency_ms' => $response->latencyMs,
            'model' => $response->model,
            'status' => 'ok',
            'context_chunk_ids' => $contextChunkIds !== [] ? array_values($contextChunkIds) : null,
        ]);

        Log::channel('json')->info('llm.chat', [
            'workspace_id' => $workspaceId,
            'chatbot_id' => $chatbotId,
            'feature' => $feature,
            'model' => $response->model,
            'prompt_tokens' => $response->promptTokens,
            'completion_tokens' => $response->completionTokens,
            'latency_ms' => $response->latencyMs,
        ]);

        return $response;
    }

    /**
     * Stream a chat completion as ordered text deltas.
     *
     * Providers that cannot stream natively still honour the same protocol:
     * their full reply is emitted as one delta. The usage meter and run ledger
     * are written when the stream finishes — even if the client disconnects
     * early, the tokens spent up to that point are still recorded.
     *
     * @param  array  $messages  [['role' => 'user', 'content' => '...'], ...]
     * @param  array  $opts  Provider options (max_tokens, temperature, ...)
     * @param  string|null  $feature  Which product feature issued the call
     * @param  list<int>  $contextChunkIds  Knowledge-base chunks injected into the prompt
     * @return \Generator<int, string> Text deltas, in order
     */
    public function streamChat(
        int $workspaceId,
        array $messages,
        array $opts = [],
        ?int $chatbotId = null,
        ?int $conversationId = null,
        ?string $feature = null,
        array $contextChunkIds = [],
    ): \Generator {
        $provider = LlmManager::forWorkspace($workspaceId);
        $start = microtime(true);
        $content = '';
        $model = $opts['model'] ?? 'unknown';
        $promptTokens = null;
        $completionTokens = null;
        $status = 'ok';

        try {
            if ($provider instanceof SupportsStreaming) {
                foreach ($provider->streamChat($messages, $opts) as $delta) {
                    $content .= $delta;

                    yield $delta;
                }
            } else {
                $response = $provider->chat($messages, $opts);
                $model = $response->model;
                $promptTokens = $response->promptTokens;
                $completionTokens = $response->completionTokens;
                $content = $response->content;

                if ($content !== '') {
                    yield $content;
                }
            }
        } catch (\Throwable $e) {
            $status = 'error';

            Log::error('LlmGateway stream failed: '.$e->getMessage(), [
                'workspace_id' => $workspaceId,
            ]);

            throw $e;
        } finally {
            // Streaming chunks do not always carry usage, so fall back to the
            // four-characters-per-token estimate the indexer uses.
            $promptTokens ??= $this->estimateTokens(array_map(
                fn ($m) => (string) ($m['content'] ?? ''),
                $messages,
            ));
            $completionTokens ??= $this->estimateTokens([$content]);
            $latency = (int) ((microtime(true) - $start) * 1000);

            UsageMeter::track($workspaceId, 'ai_tokens', $promptTokens + $completionTokens);

            $this->record($workspaceId, $feature, [
                'chatbot_id' => $chatbotId,
                'conversation_id' => $conversationId,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'cost_cents' => $this->costCents($model, $promptTokens, $completionTokens),
                'latency_ms' => $latency,
                'model' => $model,
                'status' => $status,
                'context_chunk_ids' => $contextChunkIds !== [] ? array_values($contextChunkIds) : null,
            ]);

            Log::channel('json')->info('llm.stream', [
                'workspace_id' => $workspaceId,
                'chatbot_id' => $chatbotId,
                'feature' => $feature,
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'latency_ms' => $latency,
                'status' => $status,
            ]);
        }
    }

    public function embed(int $workspaceId, array $texts): array
    {
        // Use embed-specific provider (skips Anthropic which has no embedding support)
        $provider = LlmManager::forWorkspaceEmbed($workspaceId);
        $embeddings = $provider->embed($texts);
        $tokenEstimate = array_sum(array_map(fn ($t) => (int) ceil(strlen($t) / 4), $texts));
        UsageMeter::track($workspaceId, 'ai_tokens', $tokenEstimate);

        $this->record($workspaceId, 'embed', [
            'chatbot_id' => null,
            'conversation_id' => null,
            'prompt_tokens' => $tokenEstimate,
            'completion_tokens' => 0,
            // The embedding model is not carried on the run row, so it cannot
            // be priced from `ai.pricing` yet.
            'cost_cents' => 0,
            'latency_ms' => 0,
            'model' => 'embed',
            'status' => 'ok',
        ]);

        return $embeddings;
    }

    /**
     * Estimate tokens at four characters per token (the indexer's heuristic).
     *
     * @param  list<string>  $texts
     */
    private function estimateTokens(array $texts): int
    {
        return (int) array_sum(array_map(fn (string $text) => (int) ceil(strlen($text) / 4), $texts));
    }

    /**
     * Cost of a chat completion in cents, from the configured price map.
     *
     * Returns 0 for an unpriced model, so reports under-report instead of
     * showing an invented number. Prices are cents per one million tokens.
     */
    private function costCents(string $model, int $promptTokens, int $completionTokens): int
    {
        $price = config('ai.pricing', [])[$model] ?? null;

        if (! is_array($price)) {
            return 0;
        }

        $cents = ($promptTokens / 1_000_000) * (float) ($price['input'] ?? 0)
            + ($completionTokens / 1_000_000) * (float) ($price['output'] ?? 0);

        return (int) round($cents);
    }

    /**
     * Write one run row. The ledger is reporting data, not user data, so a
     * failure to write it must never fail the AI call the user already paid for.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function record(int $workspaceId, ?string $feature, array $attributes): void
    {
        try {
            AiRun::create([
                'workspace_id' => $workspaceId,
                'feature' => $feature,
                ...$attributes,
            ]);
        } catch (\Throwable $e) {
            Log::channel('json')->warning('llm.run.ledger_failed', [
                'workspace_id' => $workspaceId,
                'feature' => $feature,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
