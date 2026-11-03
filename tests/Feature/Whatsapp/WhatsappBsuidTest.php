<?php

namespace Tests\Feature\Whatsapp;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Whatsapp\Services\CloudApiClient;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappBsuidTest extends TestCase
{
    use RefreshDatabase;

    private const BSUID = 'US.13491208655302741918';

    private function workspaceWithChannel(): Workspace
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);

        ChannelAccount::create([
            'workspace_id' => $workspace->id,
            'channel' => 'whatsapp',
            'display_name' => 'Test WA',
            'phone_number_id' => 'PHONE_ID',
            'status' => 'active',
        ]);

        return $workspace;
    }

    private function inboundPayload(array $contactEntry, array $message): array
    {
        return [
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => 'PHONE_ID'],
                        'contacts' => [$contactEntry],
                        'messages' => [$message],
                    ],
                ]],
            ]],
        ];
    }

    private function systemPayload(array $system, string $id = 'wamid.SYSTEM1'): array
    {
        return [
            'entry' => [[
                'id' => 'WABA_ID',
                'changes' => [[
                    'field' => 'messages',
                    'value' => [
                        'messaging_product' => 'whatsapp',
                        'metadata' => ['phone_number_id' => 'PHONE_ID'],
                        'messages' => [[
                            'from' => $system['previous_user_id'] ?? self::BSUID,
                            'id' => $id,
                            'timestamp' => now()->timestamp,
                            'type' => 'system',
                            'system' => $system,
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    #[Test]
    public function hidden_number_sender_is_stored_by_bsuid_and_username(): void
    {
        $workspace = $this->workspaceWithChannel();

        app(WhatsappDriver::class)->processWebhookPayload($this->inboundPayload(
            [
                'profile' => ['name' => 'Alice', 'username' => 'alice_wa'],
                'user_id' => self::BSUID,
            ],
            [
                'from' => self::BSUID,
                'from_user_id' => self::BSUID,
                'id' => 'wamid.ONE',
                'timestamp' => now()->timestamp,
                'type' => 'text',
                'text' => ['body' => 'Hello'],
            ],
        ));

        $contact = Contact::where('workspace_id', $workspace->id)->sole();

        $this->assertSame(self::BSUID, $contact->wa_user_id);
        $this->assertSame('alice_wa', $contact->wa_username);
        $this->assertNull($contact->phone_e164);
        $this->assertSame('Alice', $contact->first_name);
        $this->assertDatabaseHas('messages', ['provider_message_id' => 'wamid.ONE']);
    }

    #[Test]
    public function later_shared_phone_number_merges_into_the_same_contact(): void
    {
        $workspace = $this->workspaceWithChannel();
        $driver = app(WhatsappDriver::class);

        $driver->processWebhookPayload($this->inboundPayload(
            ['profile' => ['name' => 'Alice'], 'user_id' => self::BSUID],
            [
                'from' => self::BSUID,
                'from_user_id' => self::BSUID,
                'id' => 'wamid.ONE',
                'timestamp' => now()->timestamp,
                'type' => 'text',
                'text' => ['body' => 'Hello'],
            ],
        ));

        $driver->processWebhookPayload($this->inboundPayload(
            ['profile' => ['name' => 'Alice'], 'user_id' => self::BSUID, 'wa_id' => '15551234567'],
            [
                'from' => '15551234567',
                'from_user_id' => self::BSUID,
                'id' => 'wamid.TWO',
                'timestamp' => now()->timestamp,
                'type' => 'text',
                'text' => ['body' => 'Now with a number'],
            ],
        ));

        $this->assertSame(1, Contact::where('workspace_id', $workspace->id)->count());

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('+15551234567', $contact->phone_e164);
        $this->assertSame(self::BSUID, $contact->wa_user_id);
    }

    #[Test]
    public function system_message_user_changed_user_id_updates_the_contact(): void
    {
        $workspace = $this->workspaceWithChannel();
        $driver = app(WhatsappDriver::class);

        $driver->processWebhookPayload($this->inboundPayload(
            ['profile' => ['name' => 'Alice'], 'user_id' => self::BSUID],
            [
                'from' => self::BSUID,
                'from_user_id' => self::BSUID,
                'id' => 'wamid.ONE',
                'timestamp' => now()->timestamp,
                'type' => 'text',
                'text' => ['body' => 'Hello'],
            ],
        ));

        $driver->processWebhookPayload($this->systemPayload([
            'type' => 'user_changed_user_id',
            'user_id' => 'US.99999999999999999999',
            'previous_user_id' => self::BSUID,
        ]));

        $this->assertDatabaseHas('contacts', [
            'workspace_id' => $workspace->id,
            'wa_user_id' => 'US.99999999999999999999',
        ]);
    }

    #[Test]
    public function system_message_user_changed_number_updates_the_phone(): void
    {
        $workspace = $this->workspaceWithChannel();
        $driver = app(WhatsappDriver::class);

        $driver->processWebhookPayload($this->inboundPayload(
            ['profile' => ['name' => 'Alice'], 'user_id' => self::BSUID],
            [
                'from' => self::BSUID,
                'from_user_id' => self::BSUID,
                'id' => 'wamid.ONE',
                'timestamp' => now()->timestamp,
                'type' => 'text',
                'text' => ['body' => 'Hello'],
            ],
        ));

        $driver->processWebhookPayload($this->systemPayload([
            'type' => 'user_changed_number',
            'user_id' => self::BSUID,
            'wa_id' => '15559998888',
        ]));

        $contact = Contact::where('workspace_id', $workspace->id)->sole();
        $this->assertSame('+15559998888', $contact->phone_e164);
    }

    #[Test]
    public function outbound_to_a_bsuid_uses_recipient_type_instead_of_to(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
        ]);

        $client = new CloudApiClient('PHONE_ID', 'token');
        $client->sendText(self::BSUID, 'Hello back');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['recipient_type'] === 'individual'
                && $data['recipient'] === self::BSUID
                && ! array_key_exists('to', $data);
        });
    }

    #[Test]
    public function outbound_to_a_phone_number_still_uses_to(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT']]], 200),
        ]);

        $client = new CloudApiClient('PHONE_ID', 'token');
        $client->sendText('+15551234567', 'Hello');

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $data['to'] === '+15551234567'
                && ! array_key_exists('recipient', $data)
                && ! array_key_exists('recipient_type', $data);
        });
    }
}
