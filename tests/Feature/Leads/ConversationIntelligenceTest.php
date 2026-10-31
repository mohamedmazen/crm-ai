<?php

namespace Tests\Feature\Leads;

use App\Events\MessageReceived;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadTask;
use App\Modules\Leads\Services\UnifiedTimelineService;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 3 conversation intelligence: the lead's conversation tab, the merged
 * unified timeline, the SLA signal on unanswered threads, and the
 * `lead.customer_replied` automation trigger.
 */
class ConversationIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actor(bool $enabled = true): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => ['sales_reports' => $enabled],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace];
    }

    private function linkedContact(int $workspaceId, int $leadId): Contact
    {
        return Contact::factory()->create([
            'workspace_id' => $workspaceId,
            'lead_id' => $leadId,
        ]);
    }

    private function conversation(int $workspaceId, int $contactId, array $attributes = []): Conversation
    {
        return Conversation::create(array_merge([
            'workspace_id' => $workspaceId,
            'contact_id' => $contactId,
            'status' => 'open',
        ], $attributes));
    }

    private function message(Conversation $conversation, string $direction, $sentAt, string $body = 'Hello'): Message
    {
        return Message::create([
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $body,
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => $sentAt,
        ]);
    }

    #[Test]
    public function the_lead_detail_carries_the_conversation_and_timeline_when_enabled(): void
    {
        [$user, $workspace] = $this->actor();
        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $contact = $this->linkedContact($workspace->id, $lead->id);
        $conversation = $this->conversation($workspace->id, $contact->id, [
            'last_message_at' => now(),
            'unread_count' => 2,
        ]);
        $this->message($conversation, 'in', now()->subHour(), 'Do you have this in blue?');

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['lead' => $lead->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leadDetail.conversation.uuid', $conversation->uuid)
                ->where('leadDetail.conversation.unread_count', 2)
                ->where('leadDetail.timeline.0.type', 'message')
                ->where('leadDetail.timeline.0.body', 'Do you have this in blue?')
            );
    }

    #[Test]
    public function the_lead_detail_omits_conversation_data_when_the_feature_is_disabled(): void
    {
        [$user, $workspace] = $this->actor(false);
        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $contact = $this->linkedContact($workspace->id, $lead->id);
        $conversation = $this->conversation($workspace->id, $contact->id, ['last_message_at' => now()]);
        $this->message($conversation, 'in', now()->subHour());

        $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['lead' => $lead->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('leadDetail.conversation', null)
                ->where('leadDetail.timeline', [])
            );
    }

    #[Test]
    public function the_timeline_merges_messages_campaigns_orders_and_tasks_newest_first(): void
    {
        [, $workspace] = $this->actor();
        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $contact = $this->linkedContact($workspace->id, $lead->id);
        $conversation = $this->conversation($workspace->id, $contact->id);

        $this->message($conversation, 'in', now()->subHours(4));

        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Spring Promo',
            'channel' => 'whatsapp',
        ]);
        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
            'sent_at' => now()->subHours(3),
        ]);

        EcommerceOrder::create([
            'workspace_id' => $workspace->id,
            'store_id' => 42,
            'contact_id' => $contact->id,
            'external_order_id' => 'ord-1',
            'platform' => 'shopify',
            'number' => '#1',
            'status' => 'paid',
            'currency' => 'USD',
            'total' => 99.00,
            'placed_at' => now()->subHours(2),
        ]);

        LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'title' => 'Call back about the order',
            'type' => 'call',
            'priority' => 'high',
            'due_at' => now()->subHour(),
        ]);

        $items = app(UnifiedTimelineService::class)->forLead($lead->fresh());

        $this->assertSame(['task', 'order', 'campaign', 'message'], array_column($items, 'type'));
        $this->assertSame('Spring Promo', $items[2]['title']);
        $this->assertSame(99.0, $items[1]['meta']['total']);
    }

    #[Test]
    public function a_conversation_waits_for_a_reply_only_after_the_contacts_last_message(): void
    {
        [, $workspace] = $this->actor();
        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $contact = $this->linkedContact($workspace->id, $lead->id);
        $conversation = $this->conversation($workspace->id, $contact->id);

        $inbound = $this->message($conversation, 'in', now()->subHours(2));

        $this->assertSame(
            $inbound->sent_at->toDateTimeString(),
            $conversation->fresh()->awaitingReplySince()?->toDateTimeString(),
        );

        // The agent replied after: the ball is back in the agent's court.
        $this->message($conversation, 'out', now()->subHour());
        $this->assertNull($conversation->fresh()->awaitingReplySince());

        // An answered message in a closed thread no longer counts as waiting.
        $closed = $this->conversation($workspace->id, $contact->id, ['status' => 'resolved']);
        $this->message($closed, 'in', now()->subHours(3));
        $this->assertNull($closed->fresh()->awaitingReplySince());
    }

    #[Test]
    public function the_inbox_list_flags_a_thread_whose_contact_is_waiting(): void
    {
        [$user, $workspace] = $this->actor();
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = $this->conversation($workspace->id, $contact->id, ['last_message_at' => now()->subHours(3)]);
        $inbound = $this->message($conversation, 'in', now()->subHours(3));

        $this->actingAs($user)
            ->get(route('client.inbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('conversations.data.0.awaiting_since', $inbound->sent_at->toIso8601String())
            );
    }

    #[Test]
    public function the_inbox_list_does_not_flag_waiting_when_the_feature_is_disabled(): void
    {
        [$user, $workspace] = $this->actor(false);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = $this->conversation($workspace->id, $contact->id, ['last_message_at' => now()->subHours(3)]);
        $this->message($conversation, 'in', now()->subHours(3));

        $this->actingAs($user)
            ->get(route('client.inbox.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('conversations.data.0.awaiting_since')
            );
    }

    #[Test]
    public function an_inbound_reply_from_a_lead_linked_contact_fires_the_customer_replied_trigger(): void
    {
        Queue::fake();
        Http::fake();
        Notification::fake();

        [, $workspace] = $this->actor();
        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $contact = $this->linkedContact($workspace->id, $lead->id);
        $conversation = $this->conversation($workspace->id, $contact->id);

        $automation = Automation::create([
            'workspace_id' => $workspace->id,
            'name' => 'Nudge after reply',
            'status' => 'active',
            'trigger_type' => 'lead.customer_replied',
            'trigger_config' => [],
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['triggerType' => 'lead.customer_replied']],
            ],
            'edges' => [],
        ]);

        MessageReceived::dispatch($this->message($conversation, 'in', now(), 'I am still interested'));

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    #[Test]
    public function an_inbound_reply_from_a_contact_without_a_lead_does_not_fire_the_trigger(): void
    {
        Queue::fake();
        Http::fake();
        Notification::fake();

        [, $workspace] = $this->actor();
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'lead_id' => null]);
        $conversation = $this->conversation($workspace->id, $contact->id);

        $automation = Automation::create([
            'workspace_id' => $workspace->id,
            'name' => 'Nudge after reply',
            'status' => 'active',
            'trigger_type' => 'lead.customer_replied',
            'trigger_config' => [],
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['triggerType' => 'lead.customer_replied']],
            ],
            'edges' => [],
        ]);

        MessageReceived::dispatch($this->message($conversation, 'in', now(), 'Just browsing'));

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());
    }
}
