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

    public function test_cequens_delivery_updates_recipient_status(): void
    {
        $this->makeRecipient('CEQ_DLR1');

        $this->postJson(route('webhooks.sms.status', 'cequens'), [
            'msgid' => 'CEQ_DLR1',
            'statusid' => 1,
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'CEQ_DLR1',
            'status' => 'delivered',
        ]);
    }

    public function test_unifonic_delivery_updates_recipient_status(): void
    {
        $this->makeRecipient('UN_001');

        $this->postJson(route('webhooks.sms.status', 'unifonic'), [
            'eventName' => 'dlr',
            'body' => ['messageId' => 'UN_001', 'finalStatus' => 'DELIVRD'],
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'UN_001',
            'status' => 'delivered',
        ]);
    }

    public function test_infobip_failed_status_maps_correctly(): void
    {
        $this->makeRecipient('IB_FAIL');

        $this->postJson(route('webhooks.sms.status', 'infobip'), [
            'results' => [
                ['messageId' => 'IB_FAIL', 'status' => ['groupName' => 'UNDELIVERABLE']],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'IB_FAIL',
            'status' => 'failed',
        ]);
    }

    public function test_taqnyat_delivery_updates_recipient_status(): void
    {
        $this->makeRecipient('TAQ_DLR');

        $this->postJson(route('webhooks.sms.status', 'taqnyat'), [
            'messageId' => 'TAQ_DLR',
            'status' => 'Delivered',
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'TAQ_DLR',
            'status' => 'delivered',
        ]);
    }

    public function test_sms_misr_delivery_updates_recipient_status(): void
    {
        $this->makeRecipient('MISR_DLR1');

        $this->postJson(route('webhooks.sms.status', 'sms_misr'), [
            'SMSID' => 'MISR_DLR1',
            'status' => 'Delivered',
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'MISR_DLR1',
            'status' => 'delivered',
        ]);
    }

    public function test_msegat_failed_status_maps_correctly(): void
    {
        $this->makeRecipient('MS_DLR1');

        $this->postJson(route('webhooks.sms.status', 'msegat'), [
            'msgId' => 'MS_DLR1',
            'status' => 'Failed',
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'MS_DLR1',
            'status' => 'failed',
        ]);
    }

    public function test_generic_webhook_verifies_the_configured_secret(): void
    {
        config(['services.msegat.webhook_secret' => 's3cret']);
        $this->makeRecipient('MS_SECRET');

        $this->postJson(route('webhooks.sms.status', 'msegat'), [
            'msgId' => 'MS_SECRET',
            'status' => 'Delivered',
            'token' => 'wrong',
        ])->assertStatus(401);

        $this->postJson(route('webhooks.sms.status', 'msegat'), [
            'msgId' => 'MS_SECRET',
            'status' => 'Delivered',
            'token' => 's3cret',
        ])->assertOk();

        $this->assertDatabaseHas('campaign_recipients', [
            'provider_message_id' => 'MS_SECRET',
            'status' => 'delivered',
        ]);
    }

    public function test_unsigned_webhook_is_rejected_in_production_without_a_secret(): void
    {
        config(['services.msegat.webhook_secret' => null]);
        $this->app['env'] = 'production';

        $this->postJson(route('webhooks.sms.status', 'msegat'), [
            'msgId' => 'MS_PROD',
            'status' => 'Delivered',
        ])->assertStatus(401);
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
