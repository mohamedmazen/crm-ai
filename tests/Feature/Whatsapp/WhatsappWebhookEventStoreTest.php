<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappTemplate;
use App\Modules\Whatsapp\Models\WhatsappWebhookEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappWebhookEventStoreTest extends TestCase
{
    use RefreshDatabase;

    private string $wabaId = 'WABA_EVENT_STORE';

    private string $token = 'event-store-token';

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->createWorkspaceContext();

        WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $ctx['workspace']->id,
            'waba_id' => $this->wabaId,
            'webhook_verify_token' => $this->token,
        ]);

        WhatsappTemplate::create([
            'workspace_id' => $ctx['workspace']->id,
            'waba_id' => $this->wabaId,
            'name' => 'order_update',
            'language' => 'en',
            'status' => 'PENDING',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry' => [[
                'id' => $this->wabaId,
                'changes' => [[
                    'field' => 'message_template_status_update',
                    'value' => [
                        'event' => 'APPROVED',
                        'message_template_name' => 'order_update',
                        'message_template_language' => 'en',
                    ],
                ]],
            ]],
        ];
    }

    #[Test]
    public function webhook_entry_is_stored_processed_and_linked_to_the_workspace(): void
    {
        $this->postJson("/webhooks/whatsapp/{$this->token}", $this->payload())->assertOk();

        $event = WhatsappWebhookEvent::sole();

        $this->assertSame('processed', $event->processing_status);
        $this->assertSame(1, $event->attempts);
        $this->assertSame('message_template_status_update', $event->event_type);
        $this->assertNotNull($event->processed_at);
        $this->assertNotEmpty($event->payload);
        $this->assertDatabaseHas('whatsapp_templates', [
            'name' => 'order_update',
            'status' => 'APPROVED',
        ]);
    }

    #[Test]
    public function duplicate_delivery_does_not_store_a_second_event(): void
    {
        $this->postJson("/webhooks/whatsapp/{$this->token}", $this->payload())->assertOk();
        $this->postJson("/webhooks/whatsapp/{$this->token}", $this->payload())->assertOk();

        $this->assertDatabaseCount('whatsapp_webhook_events', 1);
        $this->assertDatabaseCount('inbound_webhook_events', 1);
    }

    #[Test]
    public function failed_events_can_be_replayed(): void
    {
        $entry = $this->payload()['entry'][0];

        $event = WhatsappWebhookEvent::create([
            'workspace_id' => WhatsappBusinessAccount::sole()->workspace_id,
            'waba_id' => $this->wabaId,
            'event_key' => hash('sha256', 'replay-me'),
            'object' => 'whatsapp_business_account',
            'event_type' => 'message_template_status_update',
            'payload' => $entry,
            'processing_status' => 'failed',
            'attempts' => 5,
            'error_message' => 'queue timeout',
            'received_at' => now()->subHour(),
        ]);

        $this->artisan('whatsapp:replay-webhook-events', ['--id' => [$event->id]])
            ->assertExitCode(0);

        $event->refresh();

        $this->assertSame('processed', $event->processing_status);
        $this->assertNull($event->error_message);
        $this->assertDatabaseHas('whatsapp_templates', [
            'name' => 'order_update',
            'status' => 'APPROVED',
        ]);
    }
}
