<?php

namespace Tests\Feature\Polish;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsStatusWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function makeRecipient(string $msgId = 'MSG123'): CampaignRecipient
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        $campaign = Campaign::factory()->create([
            'workspace_id' => $workspace->id,
            'channel' => 'sms',
            'name' => 'Test SMS',
        ]);

        return CampaignRecipient::factory()->create([
            'campaign_id' => $campaign->id,
            'provider_message_id' => $msgId,
            'status' => 'sent',
        ]);
    }

    public function test_twilio_delivery_updates_recipient_status(): void
    {
        $recipient = $this->makeRecipient('SM_TW1');

        $this->postJson(route('webhooks.sms.status', 'twilio'), [
            'MessageSid' => 'SM_TW1',
            'MessageStatus' => 'delivered',
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'SM_TW1',
            'status' => 'delivered',
        ]);
    }

    public function test_nexmo_delivery_updates_recipient_status(): void
    {
        $recipient = $this->makeRecipient('NX_001');

        $this->postJson(route('webhooks.sms.status', 'nexmo'), [
            'messageId' => 'NX_001',
            'status' => 'delivered',
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'NX_001',
            'status' => 'delivered',
        ]);
    }

    public function test_telnyx_delivery_updates_recipient_status(): void
    {
        $recipient = $this->makeRecipient('TLX_002');

        $this->postJson(route('webhooks.sms.status', 'telnyx'), [
            'data' => [
                'payload' => [
                    'id' => 'TLX_002',
                    'to' => [['status' => 'delivered']],
                ],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'TLX_002',
            'status' => 'delivered',
        ]);
    }

    public function test_clicksend_failed_status_maps_correctly(): void
    {
        $recipient = $this->makeRecipient('CS_FAIL');

        $this->postJson(route('webhooks.sms.status', 'clicksend'), [
            'message_id' => 'CS_FAIL',
            'status' => 'Failed',
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'CS_FAIL',
            'status' => 'failed',
        ]);
    }

    public function test_removed_provider_webhooks_return_not_found(): void
    {
        $this->postJson('/webhooks/sms/smsbd')->assertNotFound();
        $this->postJson('/webhooks/sms/reve')->assertNotFound();
        $this->postJson('/webhooks/sms/msg91')->assertNotFound();
        $this->postJson('/webhooks/sms/plivo')->assertNotFound();
    }

    public function test_unknown_provider_returns_404(): void
    {
        $this->postJson('/webhooks/sms/unknownprovider')->assertNotFound();
    }
}
