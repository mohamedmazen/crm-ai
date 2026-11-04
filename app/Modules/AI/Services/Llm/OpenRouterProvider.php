<?php

namespace App\Modules\AI\Services\Llm;

use Illuminate\Support\Facades\Http;

class OpenRouterProvider implements LlmProviderInterface
{
    private const BASE = 'https://openrouter.ai/api/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $chatModel = 'openrouter/free',
    ) {}

    public function chat(array $messages, array $opts = []): LlmResponse
    {
        $start = microtime(true);
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'HTTP-Referer' => config('app.url', 'https://crm.test'),
            'X-Title' => config('app.name', 'CRM'),
        ];

        $client = Http::withHeaders($headers)->retry(2, 500)->timeout(60);
        if (! app()->isProduction()) {
            $client = $client->withoutVerifying();
        }

        $resp = $client->post(self::BASE.'/chat/completions', [
            'model' => $opts['model'] ?? $this->chatModel,
            'messages' => $messages,
            'max_tokens' => $opts['max_tokens'] ?? 1024,
            'temperature' => $opts['temperature'] ?? 0.7,
        ]);

        if (! $resp->successful()) {
            throw new \RuntimeException('OpenRouter chat failed: '.$resp->body());
        }

        $json = $resp->json();
        $latency = (int) ((microtime(true) - $start) * 1000);
        $choiceMsg = $json['choices'][0]['message'] ?? [];
        $content = $choiceMsg['content'] ?? '';
        if (trim($content) === '' && ! empty($choiceMsg['reasoning'])) {
            $content = $choiceMsg['reasoning'];
        }

        return new LlmResponse(
            content: $content,
            promptTokens: $json['usage']['prompt_tokens'] ?? 0,
            completionTokens: $json['usage']['completion_tokens'] ?? 0,
            model: $json['model'] ?? $this->chatModel,
            latencyMs: $latency,
        );
    }

    public function embed(array $texts): array
    {
        return [];
    }
}
