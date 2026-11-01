<?php

namespace Tests\Feature\AI;

use App\Models\Plan;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Leads\Models\Lead;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The AI lead coach: a next step, a ready reply and an explanation, grounded in
 * the lead's own conversation.
 *
 * The model is faked throughout — what is under test is the product contract:
 * which plan may ask, that the tenant boundary holds, what the prompt carries,
 * and how unusable output is handled.
 */
class LeadCoachTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, bool>  $features */
    private function planWith(array $features): Plan
    {
        return Plan::factory()->create([
            'slug' => 'coach-'.implode('-', array_keys(array_filter($features))),
            'feature_flags' => $features,
        ]);
    }

    private function lead(int $workspaceId, array $attrs = []): Lead
    {
        return Lead::factory()->create(array_merge([
            'workspace_id' => $workspaceId,
            'name' => 'Dhaka Textiles Ltd',
            'category' => 'textile manufacturer',
        ], $attrs));
    }

    /** A lead with one linked contact and a two-message thread. */
    private function leadWithConversation(int $workspaceId): Lead
    {
        $lead = $this->lead($workspaceId);

        $contact = Contact::factory()->create([
            'workspace_id' => $workspaceId,
            'lead_id' => $lead->id,
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'contact_id' => $contact->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'Would 500 units suit your launch?',
            'status' => 'sent',
            'sent_by' => 'human',
            'sent_at' => now()->subHour(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'We need pricing for 500 units before Friday.',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);

        return $lead;
    }

    #[Test]
    public function it_returns_normalised_advice_and_meters_the_run(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));

        $this->fakeLlmGateway(<<<'JSON'
        Here is my advice:
        ```json
        {
            "next_step": "Send the 500-unit price list and offer a Friday call.",
            "why": "They asked for pricing before Friday, so the deal is time-boxed.",
            "reply": "Hi Amina,\n\nAttached is the 500-unit price list.\n\nBest,\nSam",
            "channel": "email",
            "score_reason": "They replied and asked about price, which raises buying intent."
        }
        ```
        JSON);

        $lead = $this->leadWithConversation($workspace->id);

        $response = $this->actingAs($user)
            ->postJson(route('client.leads.ai.coach', $lead->id))
            ->assertOk();

        $this->assertSame('Send the 500-unit price list and offer a Friday call.', $response->json('next_step'));
        $this->assertSame('email', $response->json('channel'));
        // Line breaks survive: a ready-to-send reply must not be flattened.
        $this->assertStringContainsString("\n", $response->json('reply'));
        $this->assertStringContainsString('500-unit price list', $response->json('reply'));
        $this->assertSame(false, $response->json('at_risk'));
        $this->assertNotEmpty($response->json('score_reason'));
    }

    #[Test]
    public function the_prompt_carries_the_lead_and_its_conversation(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));

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
            $captured = ['messages' => $messages, 'opts' => $opts, 'feature' => $feature];

            return new LlmResponse(json_encode([
                'next_step' => 'Call them.',
                'why' => 'Because.',
                'reply' => 'Hello.',
                'channel' => 'call',
                'score_reason' => 'Warm.',
            ]), 100, 50, 'test-model', 12);
        });

        $this->instance(LlmGateway::class, $mock);

        $lead = $this->leadWithConversation($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.coach', $lead->id))
            ->assertOk();

        $prompt = $captured['messages'][0]['content'];

        $this->assertSame('lead_coach', $captured['feature']);
        $this->assertArrayHasKey('system', $captured['opts']);
        $this->assertStringContainsString('Dhaka Textiles Ltd', $prompt);
        $this->assertStringContainsString('textile manufacturer', $prompt);
        // The lead's own words are in the prompt, labelled by speaker.
        $this->assertStringContainsString('lead: We need pricing for 500 units before Friday.', $prompt);
        $this->assertStringContainsString('rep: Would 500 units suit your launch?', $prompt);
    }

    #[Test]
    public function a_plan_without_ai_sales_cannot_ask_the_coach(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => false]));
        $this->fakeLlmGateway('{"next_step":"should not run"}');

        $lead = $this->lead($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.coach', $lead->id))
            ->assertStatus(402)
            ->assertJsonPath('error', 'Your plan does not include AI sales assistant.');

        $this->assertSame(0, UsageMeter::current($workspace->id, 'ai_tokens'));
    }

    #[Test]
    public function a_lead_from_another_workspace_is_not_reachable(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));

        ['workspace' => $otherWorkspace] = $this->createWorkspaceContext();
        $foreignLead = $this->lead($otherWorkspace->id);
        $this->fakeLlmGateway('{"next_step":"leak"}');

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.coach', $foreignLead->id))
            ->assertForbidden();
    }

    #[Test]
    public function asking_the_coach_without_a_provider_returns_a_readable_error(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));

        $lead = $this->lead($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.coach', $lead->id))
            ->assertStatus(422)
            ->assertJsonPath('error', 'No AI provider configured. Set one up in AI → Providers.');
    }

    #[Test]
    public function a_reply_without_usable_advice_is_reported_rather_than_shown(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));
        $this->fakeLlmGateway('I am sorry, I cannot help with that.');

        $lead = $this->lead($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.coach', $lead->id))
            ->assertStatus(422)
            ->assertJsonPath('error', 'The AI could not produce advice for this lead. Please try again.');
    }

    #[Test]
    public function advice_without_a_next_step_is_treated_as_unusable(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));
        $this->fakeLlmGateway('{"why": "no action given", "reply": "Hello"}');

        $lead = $this->lead($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.coach', $lead->id))
            ->assertStatus(422);
    }

    #[Test]
    public function an_unknown_channel_is_dropped_rather_than_shown(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['ai_sales' => true]));
        $this->fakeLlmGateway(json_encode([
            'next_step' => 'Send the proposal.',
            'why' => 'They asked for it.',
            'reply' => 'Here you go.',
            'channel' => 'carrier pigeon',
            'score_reason' => 'Warm.',
        ]));

        $lead = $this->lead($workspace->id);

        $this->actingAs($user)
            ->postJson(route('client.leads.ai.coach', $lead->id))
            ->assertOk()
            ->assertJsonPath('channel', null);
    }
}
