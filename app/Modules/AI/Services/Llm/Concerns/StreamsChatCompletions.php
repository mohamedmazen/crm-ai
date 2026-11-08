<?php

namespace App\Modules\AI\Services\Llm\Concerns;

use Illuminate\Support\Facades\Http;

/**
 * Reads an OpenAI-compatible `text/event-stream` chat completion.
 *
 * OpenAI and OpenRouter share the same wire format (`data: {...}` lines
 * terminated by `data: [DONE]`), so both providers reuse this reader.
 */
trait StreamsChatCompletions
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $payload
     * @return \Generator<int, string>
     *
     * @throws \RuntimeException when the provider rejects the request
     */
    protected function streamCompletions(string $url, array $headers, array $payload): \Generator
    {
        $client = Http::withHeaders($headers)->timeout(120);

        if (! app()->isProduction()) {
            $client = $client->withoutVerifying();
        }

        $response = $client
            ->withOptions(['stream' => true])
            ->post($url, $payload + ['stream' => true]);

        if (! $response->successful()) {
            throw new \RuntimeException('Streaming chat failed: '.$response->body());
        }

        $body = $response->toPsrResponse()->getBody();
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '') {
                if ($body->eof()) {
                    break;
                }

                continue;
            }

            $buffer .= $chunk;

            while (($position = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $position));
                $buffer = substr($buffer, $position + 1);

                if ($line === '' || ! str_starts_with($line, 'data:')) {
                    continue;
                }

                $data = trim(substr($line, 5));

                if ($data === '[DONE]') {
                    return;
                }

                $json = json_decode($data, true);

                if (! is_array($json)) {
                    continue;
                }

                $delta = $json['choices'][0]['delta']['content'] ?? null;

                if (is_string($delta) && $delta !== '') {
                    yield $delta;
                }
            }
        }
    }
}
