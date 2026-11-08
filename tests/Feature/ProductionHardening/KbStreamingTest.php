<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\LlmGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Streaming chatbot replies over server-sent events.
 */
class KbStreamingTest extends TestCase
{
    use RefreshDatabase;

    private function openAiConfig(int $workspaceId): void
    {
        AiProviderConfig::create([
            'workspace_id' => $workspaceId,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);
    }

    private function chatbot(int $workspaceId): AiChatbot
    {
        return AiChatbot::create([
            'workspace_id' => $workspaceId,
            'name' => 'Streaming Bot',
            'enabled' => true,
        ]);
    }

    private function sseBody(array $deltas): string
    {
        $body = '';

        foreach ($deltas as $delta) {
            $body .= 'data: '.json_encode(['choices' => [['delta' => ['content' => $delta]]]])."\n\n";
        }

        return $body."data: [DONE]\n\n";
    }

    #[Test]
    public function the_playground_streams_deltas_as_server_sent_events(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->openAiConfig($workspace->id);
        $bot = $this->chatbot($workspace->id);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response($this->sseBody(['Hello', ', ', 'world!']), 200),
        ]);

        $response = $this->actingAs($user)->post(
            route('client.ai.chatbots.playground.stream', $bot->uuid),
            ['message' => 'Hi there'],
        );

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', (string) $response->headers->get('Content-Type'));

        $content = $response->streamedContent();

        $this->assertStringContainsString('data: {"delta":"Hello"}', $content);
        $this->assertStringContainsString('data: {"delta":", "}', $content);
        $this->assertStringContainsString('data: {"delta":"world!"}', $content);
        $this->assertStringContainsString('data: [DONE]', $content);

        $run = AiRun::where('workspace_id', $workspace->id)
            ->where('chatbot_id', $bot->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('ok', $run->status);
        $this->assertGreaterThan(0, $run->prompt_tokens);
        $this->assertGreaterThan(0, $run->completion_tokens);
    }

    #[Test]
    public function a_provider_without_native_streaming_emits_the_full_reply_as_one_delta(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'anthropic',
            'credentials' => ['api_key' => 'sk-ant-test'],
            'default_model_chat' => 'claude-3-haiku-20240307',
            'enabled' => true,
        ]);

        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['text' => 'Full reply here.']],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 3],
                'model' => 'claude-3-haiku-20240307',
            ], 200),
        ]);

        $deltas = iterator_to_array(app(LlmGateway::class)->streamChat(
            $workspace->id,
            [['role' => 'user', 'content' => 'hi']],
        ));

        $this->assertSame(['Full reply here.'], array_values($deltas));

        $run = AiRun::where('workspace_id', $workspace->id)->latest('id')->firstOrFail();
        $this->assertSame('ok', $run->status);
        $this->assertSame(12, $run->prompt_tokens);
        $this->assertSame(3, $run->completion_tokens);
    }

    #[Test]
    public function streaming_can_be_disabled(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $this->openAiConfig($workspace->id);
        $bot = $this->chatbot($workspace->id);

        config(['ai.streaming.enabled' => false]);

        $this->actingAs($user)
            ->post(route('client.ai.chatbots.playground.stream', $bot->uuid), ['message' => 'Hi'])
            ->assertNotFound();
    }

    #[Test]
    public function a_streaming_failure_records_an_error_run(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->openAiConfig($workspace->id);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response('provider down', 500),
        ]);

        $iterator = app(LlmGateway::class)->streamChat(
            $workspace->id,
            [['role' => 'user', 'content' => 'hi']],
        );

        try {
            iterator_to_array($iterator);
            $this->fail('Expected the provider failure to be rethrown.');
        } catch (\RuntimeException) {
            // Expected.
        }

        $run = AiRun::where('workspace_id', $workspace->id)->latest('id')->firstOrFail();
        $this->assertSame('error', $run->status);
    }

    #[Test]
    public function the_streamed_reply_falls_back_when_the_provider_fails(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->openAiConfig($workspace->id);
        $bot = $this->chatbot($workspace->id);
        $bot->update(['fallback_reply' => 'Please contact support.']);

        Http::fake([
            'api.openai.com/v1/chat/completions' => Http::response('provider down', 500),
        ]);

        $deltas = iterator_to_array(app(ChatbotRunner::class)->streamForApi($bot, 'hi', $workspace->id));

        $this->assertSame(['Please contact support.'], array_values($deltas));
    }
}
