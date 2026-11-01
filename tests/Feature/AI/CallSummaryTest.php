<?php

namespace Tests\Feature\AI;

use App\Models\Plan;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\CallLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 6 call summaries: a transcript becomes a saved summary, action items and
 * sentiment, through the same gate and ledger as every other AI feature.
 */
class CallSummaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @param  array<string, bool>  $features */
    private function planWith(array $features): Plan
    {
        return Plan::factory()->create(['feature_flags' => $features]);
    }

    private function makeCall(int $workspaceId, array $attrs = []): CallLog
    {
        return CallLog::create(array_merge([
            'workspace_id' => $workspaceId,
            'direction' => CallLog::DIRECTION_OUTBOUND,
            'from_number' => '+15550001111',
            'to_number' => '+15550002222',
            'status' => CallLog::STATUS_COMPLETED,
            'duration_secs' => 252,
        ], $attrs));
    }

    #[Test]
    public function it_summarises_the_stored_transcript_and_saves_the_result(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['call_logs' => true, 'ai_sales' => true]));

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id, 'name' => 'Dhaka Textiles Ltd']);

        $call = $this->makeCall($workspace->id, [
            'lead_id' => $lead->id,
            'transcript' => 'Rep: We can do 500 units by Friday. Lead: Send the price list and I will confirm.',
        ]);

        $captured = null;

        $mock = Mockery::mock(LlmGateway::class);
        $mock->shouldReceive('chat')->andReturnUsing(function (
            int $workspaceId,
            array $messages,
            array $opts = [],
            ?int $chatbotId = null,
            ?int $conversationId = null,
            ?string $feature = null,
        ) use (&$captured) {
            $captured = ['messages' => $messages, 'feature' => $feature];

            return new LlmResponse(<<<'JSON'
            ```json
            {
                "summary": "The lead wants 500 units by Friday and asked for a price list before confirming.",
                "action_items": ["Send the 500-unit price list today", "Call back Friday morning"],
                "sentiment": "positive"
            }
            ```
            JSON, 120, 60, 'test-model', 20);
        });

        $this->instance(LlmGateway::class, $mock);

        $response = $this->actingAs($user)
            ->postJson(route('client.sales.calls.summary', $call->id))
            ->assertOk();

        $this->assertSame('call_summary', $captured['feature']);
        $this->assertStringContainsString('500 units by Friday', $captured['messages'][0]['content']);
        $this->assertStringContainsString('Dhaka Textiles Ltd', $captured['messages'][0]['content']);

        $this->assertStringContainsString('price list', $response->json('summary'));
        $this->assertSame(['Send the 500-unit price list today', 'Call back Friday morning'], $response->json('summary_data.action_items'));
        $this->assertSame('positive', $response->json('summary_data.sentiment'));
        $this->assertNotNull($response->json('generated_at'));

        $call->refresh();

        $this->assertStringContainsString('price list', $call->summary);
        $this->assertSame('positive', $call->summary_data['sentiment']);
        $this->assertNotNull($call->summary_generated_at);
        // The transcript is not rewritten by summarising.
        $this->assertStringContainsString('500 units by Friday', $call->transcript);
    }

    #[Test]
    public function a_transcript_sent_with_the_request_is_stored_before_summarising(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['call_logs' => true, 'ai_sales' => true]));

        $call = $this->makeCall($workspace->id);

        $this->fakeLlmGateway(json_encode([
            'summary' => 'They asked for a quote.',
            'action_items' => [],
            'sentiment' => 'neutral',
        ]));

        $this->actingAs($user)
            ->postJson(route('client.sales.calls.summary', $call->id), [
                'transcript' => 'Lead: Please send the quote for 20 seats.',
            ])
            ->assertOk();

        $call->refresh();

        $this->assertSame('Lead: Please send the quote for 20 seats.', $call->transcript);
        $this->assertSame('They asked for a quote.', $call->summary);
    }

    #[Test]
    public function a_call_without_a_transcript_is_rejected_before_any_ai_call(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['call_logs' => true, 'ai_sales' => true]));

        $call = $this->makeCall($workspace->id);

        // No expectation set: any gateway call would fail this test.
        $this->instance(LlmGateway::class, Mockery::mock(LlmGateway::class));

        $this->actingAs($user)
            ->postJson(route('client.sales.calls.summary', $call->id))
            ->assertStatus(422)
            ->assertJsonPath('error', 'Add a transcript before generating a summary.');
    }

    #[Test]
    public function a_plan_without_ai_sales_cannot_summarise(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['call_logs' => true, 'ai_sales' => false]));

        $call = $this->makeCall($workspace->id, ['transcript' => 'Anything.']);
        $this->fakeLlmGateway('{"summary":"should not run"}');

        $this->actingAs($user)
            ->postJson(route('client.sales.calls.summary', $call->id))
            ->assertStatus(402)
            ->assertJsonPath('error', 'Your plan does not include AI sales assistant.');

        $this->assertNull($call->fresh()->summary);
    }

    #[Test]
    public function a_call_from_another_workspace_is_not_reachable(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['call_logs' => true, 'ai_sales' => true]));

        ['workspace' => $other] = $this->createWorkspaceContext();
        $foreign = $this->makeCall($other->id, ['transcript' => 'Secret call.']);
        $this->fakeLlmGateway('{"summary":"leak"}');

        $this->actingAs($user)
            ->postJson(route('client.sales.calls.summary', $foreign->id))
            ->assertForbidden();

        $this->assertNull($foreign->fresh()->summary);
    }

    #[Test]
    public function summarising_without_a_provider_returns_a_readable_error(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['call_logs' => true, 'ai_sales' => true]));

        $call = $this->makeCall($workspace->id, ['transcript' => 'A transcript.']);

        $this->actingAs($user)
            ->postJson(route('client.sales.calls.summary', $call->id))
            ->assertStatus(422)
            ->assertJsonPath('error', 'No AI provider configured. Set one up in AI → Providers.');
    }

    #[Test]
    public function an_unusable_reply_leaves_the_existing_summary_untouched(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['call_logs' => true, 'ai_sales' => true]));

        $call = $this->makeCall($workspace->id, [
            'transcript' => 'A transcript.',
            'summary' => 'The original summary.',
        ]);

        $this->fakeLlmGateway('I am sorry, I cannot summarise that.');

        $this->actingAs($user)
            ->postJson(route('client.sales.calls.summary', $call->id))
            ->assertStatus(422)
            ->assertJsonPath('error', 'The AI could not summarise this call. Please try again.');

        $this->assertSame('The original summary.', $call->fresh()->summary);
    }

    #[Test]
    public function action_items_and_sentiment_are_normalised(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['call_logs' => true, 'ai_sales' => true]));

        $call = $this->makeCall($workspace->id, ['transcript' => 'A transcript.']);

        $this->fakeLlmGateway(json_encode([
            'summary' => '  A   short summary.  ',
            'action_items' => ['Send the quote', '', 42, '  Call  Friday ', 'Book a demo', 'Email legal', 'Ship the order', 'Sixth item'],
            'sentiment' => 'ecstatic',
        ]));

        $response = $this->actingAs($user)
            ->postJson(route('client.sales.calls.summary', $call->id))
            ->assertOk();

        $this->assertSame('A short summary.', $response->json('summary'));
        $this->assertSame(
            ['Send the quote', 'Call Friday', 'Book a demo', 'Email legal', 'Ship the order'],
            $response->json('summary_data.action_items'),
        );
        $this->assertNull($response->json('summary_data.sentiment'));
    }

    #[Test]
    public function the_call_log_list_carries_the_summary_but_not_the_transcript(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['call_logs' => true, 'ai_sales' => true]));

        $call = $this->makeCall($workspace->id, [
            'transcript' => 'A long transcript that should not reach the list payload.',
            'summary' => 'A saved summary.',
            'summary_data' => ['action_items' => ['Call back'], 'sentiment' => 'neutral'],
            'summary_generated_at' => now(),
        ]);

        $props = $this->actingAs($user)
            ->get(route('client.sales.calls.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $row = $props['calls']['data'][0];

        $this->assertTrue($props['aiEnabled']);
        $this->assertSame($call->id, $row['id']);
        $this->assertSame('A saved summary.', $row['summary']);
        $this->assertSame('neutral', $row['summary_data']['sentiment']);
        $this->assertTrue((bool) $row['has_transcript']);
        $this->assertArrayNotHasKey('transcript', $row);
    }

    #[Test]
    public function a_manually_logged_call_can_carry_a_transcript(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['call_logs' => true, 'ai_sales' => true]));

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);

        $this->actingAs($user)
            ->post(route('client.sales.calls.store'), [
                'lead_id' => $lead->id,
                'direction' => 'outbound',
                'status' => 'completed',
                'duration_secs' => 90,
                'notes' => 'Asked for a quote.',
                'transcript' => 'Lead: Please send the quote.',
            ])
            ->assertRedirect();

        $call = CallLog::forWorkspace($workspace->id)->firstOrFail();

        $this->assertSame('Lead: Please send the quote.', $call->transcript);
        $this->assertTrue($call->hasTranscript());
    }
}
