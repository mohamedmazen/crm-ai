<?php

namespace Tests\Feature\Whatsapp;

use App\Events\MessageReceived;
use App\Models\Plan;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Whatsapp\Jobs\RequestCoexistenceSyncJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappCoexistencePendingContact;
use App\Modules\Whatsapp\Services\WhatsappCoexistenceContactImporter;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappCoexistenceSyncTest extends TestCase
{
    use RefreshDatabase;

    private const WABA_ID = 'WABA_COEX';

    private const PHONE_ID = 'PHONE_COEX';

    protected function setUp(): void
    {
        parent::setUp();

        config(['whatsapp.coexistence.enabled' => true]);
    }

    /** A coexistence WABA and the active channel account feeding its webhooks. */
    private function coexistenceAccount(int $workspaceId, string $wabaId = self::WABA_ID): WhatsappBusinessAccount
    {
        $waba = WhatsappBusinessAccount::factory()->coexistence()->create([
            'workspace_id' => $workspaceId,
            'waba_id' => $wabaId,
            'coexistence_linked_at' => now(),
        ]);

        ChannelAccount::create([
            'workspace_id' => $workspaceId,
            'channel' => 'whatsapp',
            'display_name' => 'Coexistence WA',
            'phone_number_id' => self::PHONE_ID,
            'business_account_id' => $wabaId,
            'status' => 'active',
        ]);

        return $waba;
    }

    /** @param  array<string, mixed>  $value */
    private function payload(string $field, array $value, string $entryId = self::WABA_ID): array
    {
        return [
            'entry' => [[
                'id' => $entryId,
                'changes' => [[
                    'field' => $field,
                    'value' => $value,
                ]],
            ]],
        ];
    }

    private function planWith(array $limits, bool $coexistence = true): Plan
    {
        return Plan::factory()->create([
            'limits' => $limits,
            'feature_flags' => ['whatsapp_coexistence' => $coexistence],
        ]);
    }

    #[Test]
    public function address_book_contacts_are_staged_when_the_plan_contact_cap_is_full(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['max_contacts' => 1]));
        $this->coexistenceAccount($workspace->id);

        app(WhatsappDriver::class)->processWebhookPayload($this->payload('smb_app_state_sync', [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'state_sync' => [
                ['type' => 'contact', 'action' => 'add', 'contact' => ['phone_number' => '15550000001', 'first_name' => 'One']],
                ['type' => 'contact', 'action' => 'add', 'contact' => ['phone_number' => '15550000002', 'first_name' => 'Two']],
            ],
        ]));

        // Exactly one contact fits the cap; the overflow waits in staging, never dropped.
        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
        $this->assertDatabaseHas('whatsapp_coexistence_pending_contacts', [
            'workspace_id' => $workspace->id,
            'phone' => '+15550000002',
            'status' => WhatsappCoexistencePendingContact::STATUS_PENDING,
            'reason' => 'plan_contact_limit',
        ]);
    }

    #[Test]
    public function removed_address_book_contacts_are_never_deleted(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([]));
        $this->coexistenceAccount($workspace->id);

        $driver = app(WhatsappDriver::class);

        $driver->processWebhookPayload($this->payload('smb_app_state_sync', [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'state_sync' => [
                ['type' => 'contact', 'action' => 'add', 'contact' => ['phone_number' => '15550000009', 'first_name' => 'Keep Me']],
            ],
        ]));

        $driver->processWebhookPayload($this->payload('smb_app_state_sync', [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'state_sync' => [
                ['type' => 'contact', 'action' => 'remove', 'contact' => ['phone_number' => '15550000009']],
            ],
        ]));

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function staged_contacts_are_imported_after_capacity_frees_up(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith(['max_contacts' => 5]));
        $this->coexistenceAccount($workspace->id);

        WhatsappCoexistencePendingContact::create([
            'workspace_id' => $workspace->id,
            'waba_id' => self::WABA_ID,
            'phone' => '+15550000042',
            'display_name' => 'Waiting',
            'source' => 'state_sync',
            'status' => WhatsappCoexistencePendingContact::STATUS_PENDING,
            'reason' => 'plan_contact_limit',
        ]);

        $result = app(WhatsappCoexistenceContactImporter::class)->retryPending($workspace);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(0, $result['remaining']);
        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $workspace->id,
            'phone_e164' => '+15550000042',
        ]);
        $this->assertDatabaseHas('whatsapp_coexistence_pending_contacts', [
            'workspace_id' => $workspace->id,
            'phone' => '+15550000042',
            'status' => WhatsappCoexistencePendingContact::STATUS_IMPORTED,
        ]);
    }

    #[Test]
    public function history_import_is_silent(): void
    {
        Event::fake([MessageReceived::class]);

        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([]));
        $waba = $this->coexistenceAccount($workspace->id);

        app(WhatsappDriver::class)->processWebhookPayload($this->payload('history', [
            'metadata' => ['phone_number_id' => self::PHONE_ID, 'display_phone_number' => '201000000000'],
            'history' => [[
                'metadata' => ['progress' => 100],
                'threads' => [[
                    'context' => ['wa_id' => '15551234567'],
                    'messages' => [[
                        'from' => '15551234567',
                        'id' => 'wamid.HIST1',
                        'timestamp' => now()->subDay()->timestamp,
                        'type' => 'text',
                        'text' => ['body' => 'An old message'],
                    ]],
                ]],
            ]],
        ]));

        $message = Message::where('provider_message_id', 'wamid.HIST1')->sole();

        $this->assertTrue($message->is_history_import);
        $this->assertSame('coexistence_history', $message->source);
        $this->assertSame('in', $message->direction);

        // Silent import: no unread badge, no notifications, no automations.
        $conversation = Conversation::where('contact_id', $message->conversation->contact_id)->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(0, $conversation->unread_count);
        Event::assertNotDispatched(MessageReceived::class);

        $this->assertSame(
            'completed',
            $waba->fresh()->history_sync_status,
        );
    }

    #[Test]
    public function a_declined_history_sync_marks_the_import_declined(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([]));
        $waba = $this->coexistenceAccount($workspace->id);

        app(WhatsappDriver::class)->processWebhookPayload($this->payload('history', [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'history' => [[
                'errors' => [['code' => 2593109, 'title' => 'Business declined to share history']],
            ]],
        ]));

        $this->assertSame('declined', $waba->fresh()->history_sync_status);
    }

    #[Test]
    public function message_echoes_are_badged_and_deduplicated(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([]));
        $this->coexistenceAccount($workspace->id);

        $driver = app(WhatsappDriver::class);

        $echo = [
            'metadata' => ['phone_number_id' => self::PHONE_ID, 'display_phone_number' => '201000000000'],
            'contacts' => [['wa_id' => '15551234567', 'profile' => ['name' => 'Bob']]],
            'message_echoes' => [[
                'id' => 'wamid.ECHO1',
                'to' => '15551234567',
                'timestamp' => now()->timestamp,
                'type' => 'text',
                'text' => ['body' => 'Sent from the phone app'],
            ]],
        ];

        $driver->processWebhookPayload($this->payload('smb_message_echoes', $echo));
        $driver->processWebhookPayload($this->payload('smb_message_echoes', $echo));

        $messages = Message::where('provider_message_id', 'wamid.ECHO1')->get();
        $this->assertCount(1, $messages);
        $this->assertSame('whatsapp_business_app', $messages->first()->source);
        $this->assertSame('out', $messages->first()->direction);
        $this->assertSame('sent from the phone app', strtolower((string) $messages->first()->body));
    }

    #[Test]
    public function coexistence_webhooks_are_ignored_for_standard_cloud_api_accounts(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([]));

        WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $workspace->id,
            'waba_id' => self::WABA_ID,
        ]);
        ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Standard WA',
            'phone_number_id' => self::PHONE_ID,
            'business_account_id' => self::WABA_ID,
            'status' => 'active',
        ]);

        app(WhatsappDriver::class)->processWebhookPayload($this->payload('smb_app_state_sync', [
            'metadata' => ['phone_number_id' => self::PHONE_ID],
            'state_sync' => [
                ['type' => 'contact', 'action' => 'add', 'contact' => ['phone_number' => '15550000077']],
            ],
        ]));

        $this->assertSame(0, Contact::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function account_update_suspends_and_reconnect_restores_the_number(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([]));
        $waba = $this->coexistenceAccount($workspace->id);

        $driver = app(WhatsappDriver::class);

        $driver->processWebhookPayload($this->payload('account_update', [
            'event' => 'PARTNER_REMOVED',
            'waba_info' => ['waba_id' => self::WABA_ID],
            'disconnection_info' => ['reason' => 'user_initiated', 'initiated_by' => 'CUSTOMER'],
        ]));

        $this->assertSame(WhatsappBusinessAccount::COEXISTENCE_SUSPENDED, $waba->fresh()->coexistence_status);
        $this->assertSame('inactive', ChannelAccount::where('business_account_id', self::WABA_ID)->sole()->status);

        $driver->processWebhookPayload($this->payload('account_update', [
            'event' => 'ACCOUNT_RECONNECTED',
            'waba_info' => ['waba_id' => self::WABA_ID],
        ]));

        $this->assertSame(WhatsappBusinessAccount::COEXISTENCE_ACTIVE, $waba->fresh()->coexistence_status);
        $this->assertSame('active', ChannelAccount::where('business_account_id', self::WABA_ID)->sole()->status);
    }

    #[Test]
    public function consent_queues_the_one_shot_sync_request(): void
    {
        Queue::fake();

        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([]));
        $waba = $this->coexistenceAccount($workspace->id);

        $this->actingAs($user)
            ->post(route('client.whatsapp.setup.coexistence.consent', ['waba' => $waba->id]))
            ->assertRedirect();

        // A second consent click must not enqueue a duplicate sync request.
        $this->actingAs($user)
            ->post(route('client.whatsapp.setup.coexistence.consent', ['waba' => $waba->id]))
            ->assertRedirect();

        Queue::assertPushed(RequestCoexistenceSyncJob::class, 1);
        $this->assertSame('consented', $waba->fresh()->history_sync_status);
        $this->assertNotNull($waba->fresh()->history_sync_consented_at);
    }

    #[Test]
    public function consent_after_the_window_closes_marks_the_sync_expired(): void
    {
        Queue::fake();

        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([]));
        $waba = $this->coexistenceAccount($workspace->id);
        $waba->update(['coexistence_linked_at' => now()->subHours(25)]);

        $this->actingAs($user)
            ->post(route('client.whatsapp.setup.coexistence.consent', ['waba' => $waba->id]))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertSame('expired', $waba->fresh()->history_sync_status);
    }

    #[Test]
    public function declining_the_import_keeps_the_number_connected(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([]));
        $waba = $this->coexistenceAccount($workspace->id);

        $this->actingAs($user)
            ->post(route('client.whatsapp.setup.coexistence.decline', ['waba' => $waba->id]))
            ->assertRedirect();

        $this->assertSame('declined', $waba->fresh()->history_sync_status);
        $this->assertSame(WhatsappBusinessAccount::COEXISTENCE_ACTIVE, $waba->fresh()->coexistence_status);
    }

    #[Test]
    public function coexistence_is_rejected_when_the_plan_does_not_include_it(): void
    {
        Queue::fake();

        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([], coexistence: false));
        $waba = $this->coexistenceAccount($workspace->id);

        $this->actingAs($user)
            ->post(route('client.whatsapp.setup.coexistence.consent', ['waba' => $waba->id]))
            ->assertSessionHas('error');

        Queue::assertNothingPushed();
        $this->assertSame('none', $waba->fresh()->history_sync_status);
    }

    #[Test]
    public function disconnect_archives_the_account_without_deleting_data(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, $this->planWith([]));
        $waba = $this->coexistenceAccount($workspace->id);

        Contact::create([
            'workspace_id' => $workspace->id,
            'phone_e164' => '+15550000123',
            'first_name' => 'Kept',
        ]);

        $this->actingAs($user)
            ->post(route('client.whatsapp.setup.coexistence.disconnect', ['waba' => $waba->id]))
            ->assertRedirect();

        $this->assertSame(WhatsappBusinessAccount::COEXISTENCE_DISCONNECTED, $waba->fresh()->coexistence_status);
        $this->assertSame('inactive', ChannelAccount::where('business_account_id', self::WABA_ID)->sole()->status);
        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());
    }
}
