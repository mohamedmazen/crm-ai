<?php

namespace App\Modules\AI\Services\Llm;

/**
 * A provider that can stream a chat completion as it is generated.
 *
 * Providers that do not implement this interface still work with streaming
 * callers: {@see LlmGateway::streamChat()} emits their full reply as a single
 * delta so the UI contract stays the same.
 */
interface SupportsStreaming
{
    /**
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  array<string, mixed>  $opts
     * @return \Generator<int, string> Text deltas, in order
     */
    public function streamChat(array $messages, array $opts = []): \Generator;
}
