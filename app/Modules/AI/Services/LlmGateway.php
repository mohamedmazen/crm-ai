<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\Broadcasting\Models\UsageMeter;
use Illuminate\Support\Facades\Log;

class LlmGateway
{
    /**
     * @param  array  $messages  [['role' => 'user', 'content' => '...'], ...]
     * @param  array  $opts  Provider options (max_tokens, temperature, system, ...)
     * @param  string|null  $feature  Which product feature issued the call, for the run ledger
     */
    public function chat(
        int $workspaceId,
        array $messages,
        array $opts = [],
        ?int $chatbotId = null,
        ?int $conversationId = null,
        ?string $feature = null,
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
            'cost_cents' => 0,
            'latency_ms' => $response->latencyMs,
            'model' => $response->model,
            'status' => 'ok',
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
            'cost_cents' => 0,
            'latency_ms' => 0,
            'model' => 'embed',
            'status' => 'ok',
        ]);

        return $embeddings;
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
