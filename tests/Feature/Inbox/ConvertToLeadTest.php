<?php

namespace Tests\Feature\Inbox;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Ecommerce\Models\EcommerceCart;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 3 conversation intelligence: the one-click "convert to lead" action in
 * the inbox. It is gated on `feature:sales_reports`, must never cross a
 * workspace boundary, must be idempotent, and seeds the deal value from the
 * contact's commerce history.
 */
class ConvertToLeadTest extends TestCase
{
    use RefreshDatabase;

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

    private function conversationFor(Contact $contact): Conversation
    {
        return Conversation::create([
            'workspace_id' => $contact->workspace_id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
    }

    #[Test]
    public function converting_a_contact_creates_a_linked_lead_and_logs_the_conversion(): void
    {
        [$user, $workspace] = $this->actor();
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'first_name' => 'Nadia',
            'last_name' => 'Rahman',
            'email' => 'nadia@example.com',
        ]);
        $conversation = $this->conversationFor($contact);

        $this->actingAs($user)
            ->post(route('client.inbox.convert-to-lead', $conversation))
            ->assertRedirect()
            ->assertSessionHas('success');

        $lead = Lead::where('workspace_id', $workspace->id)->first();

        $this->assertNotNull($lead);
        $this->assertSame('Nadia Rahman', $lead->name);
        $this->assertSame('nadia@example.com', $lead->email);
        $this->assertSame($lead->id, (int) $contact->fresh()->lead_id);

        $activity = LeadActivity::where('lead_id', $lead->id)->first();
        $this->assertNotNull($activity);
        $this->assertSame('inbox', $activity->meta['source']);
        $this->assertSame($conversation->id, $activity->meta['conversation_id']);
    }

    #[Test]
    public function converting_twice_does_not_create_a_duplicate_lead(): void
    {
        [$user, $workspace] = $this->actor();
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = $this->conversationFor($contact);

        $this->actingAs($user)->post(route('client.inbox.convert-to-lead', $conversation));
        $this->actingAs($user)->post(route('client.inbox.convert-to-lead', $conversation));

        $this->assertSame(1, Lead::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function an_existing_lead_matching_the_contact_email_is_linked_instead_of_created(): void
    {
        [$user, $workspace] = $this->actor();
        $existing = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'email' => 'returning@example.com',
        ]);
        $contact = Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'email' => 'returning@example.com',
        ]);
        $conversation = $this->conversationFor($contact);

        $this->actingAs($user)->post(route('client.inbox.convert-to-lead', $conversation));

        $this->assertSame(1, Lead::where('workspace_id', $workspace->id)->count());
        $this->assertSame($existing->id, (int) $contact->fresh()->lead_id);
    }

    #[Test]
    public function the_gate_blocks_conversion_when_the_plan_disables_it(): void
    {
        [$user, $workspace] = $this->actor(false);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = $this->conversationFor($contact);

        $this->actingAs($user)
            ->post(route('client.inbox.convert-to-lead', $conversation))
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('upgrade_required', true);

        $this->assertSame(0, Lead::where('workspace_id', $workspace->id)->count());
        $this->assertNull($contact->fresh()->lead_id);
    }

    #[Test]
    public function another_workspaces_conversation_cannot_be_converted(): void
    {
        [$user] = $this->actor();

        [, $otherWorkspace] = $this->actor();
        $foreignContact = Contact::factory()->create(['workspace_id' => $otherWorkspace->id]);
        $foreignConversation = $this->conversationFor($foreignContact);

        $this->actingAs($user)
            ->post(route('client.inbox.convert-to-lead', $foreignConversation))
            ->assertForbidden();

        $this->assertSame(0, Lead::where('workspace_id', $otherWorkspace->id)->count());
        $this->assertNull($foreignContact->fresh()->lead_id);
    }

    #[Test]
    public function the_deal_value_comes_from_the_contacts_orders_excluding_cancellations(): void
    {
        [$user, $workspace] = $this->actor();
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = $this->conversationFor($contact);

        $this->order($workspace->id, $contact->id, 'ord-1', 120.50, 'USD');
        $this->order($workspace->id, $contact->id, 'ord-2', 79.50, 'USD');
        $this->order($workspace->id, $contact->id, 'ord-3', 500.00, 'USD', 'cancelled');

        $this->actingAs($user)->post(route('client.inbox.convert-to-lead', $conversation));

        $lead = Lead::where('workspace_id', $workspace->id)->first();

        $this->assertSame('200.00', $lead->deal_value);
        $this->assertSame('USD', $lead->currency);
    }

    #[Test]
    public function the_deal_value_falls_back_to_the_contacts_cart_when_there_are_no_orders(): void
    {
        [$user, $workspace] = $this->actor();
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id]);
        $conversation = $this->conversationFor($contact);

        EcommerceCart::create([
            'workspace_id' => $workspace->id,
            'store_id' => 42,
            'contact_id' => $contact->id,
            'external_id' => 'cart-1',
            'total' => 75.25,
            'currency' => 'EUR',
        ]);

        $this->actingAs($user)->post(route('client.inbox.convert-to-lead', $conversation));

        $lead = Lead::where('workspace_id', $workspace->id)->first();

        $this->assertSame('75.25', $lead->deal_value);
        $this->assertSame('EUR', $lead->currency);
    }

    private function order(int $workspaceId, int $contactId, string $externalId, float $total, string $currency, ?string $status = 'paid'): EcommerceOrder
    {
        return EcommerceOrder::create([
            'workspace_id' => $workspaceId,
            'store_id' => 42,
            'contact_id' => $contactId,
            'external_order_id' => $externalId,
            'platform' => 'shopify',
            'number' => '#'.$externalId,
            'status' => $status,
            'currency' => $currency,
            'total' => $total,
            'placed_at' => now(),
        ]);
    }
}
