<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Shared\Contracts\ChannelDriverInterface;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use App\Modules\Shared\Services\ChannelManager;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappSendGuardTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function sending_routes_carry_the_whatsapp_rate_limiter(): void
    {
        foreach (['client.inbox.reply', 'client.inbox.share-product', 'client.inbox.start', 'client.inbox.upload-media'] as $route) {
            $this->assertContains(
                'throttle:whatsapp-send',
                Route::getRoutes()->getByName($route)->gatherMiddleware(),
                "{$route} is missing the whatsapp-send rate limiter."
            );
        }
    }

    #[Test]
    public function outbound_whatsapp_reply_is_audited(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();

        $account = ChannelAccount::create([
            'workspace_id' => $ws->id,
            'channel' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'Support',
            'phone_number_id' => 'PHONE_AUDIT',
        ]);

        $contact = Contact::create([
            'workspace_id' => $ws->id,
            'phone_e164' => '+201000000001',
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $ws->id,
            'channel_account_id' => $account->id,
            'contact_id' => $contact->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $this->openWhatsappWindow($conversation);

        $driver = Mockery::mock(ChannelDriverInterface::class);
        $driver->shouldReceive('send')->andReturn('wamid.AUDITED');

        $manager = Mockery::mock(ChannelManager::class);
        $manager->shouldReceive('driver')->andReturn($driver);
        $this->app->instance(ChannelManager::class, $manager);

        $this->actingAs($user)->postJson(route('client.inbox.reply', $conversation->uuid), [
            'body' => 'Hello',
            'type' => 'text',
        ])->assertOk()->assertJsonPath('error', null);

        $message = Message::where('direction', 'out')->sole();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'whatsapp.message_sent',
            'auditable_type' => $message->getMorphClass(),
            'auditable_id' => $message->id,
        ]);
    }

    #[Test]
    public function disconnect_unsubscribes_from_meta_and_is_audited(): void
    {
        ['user' => $user, 'workspace' => $ws] = $this->createWorkspaceContext();

        $waba = WhatsappBusinessAccount::factory()->create([
            'workspace_id' => $ws->id,
            'waba_id' => 'WABA_DISCONNECT',
            'webhook_verify_token' => 'disconnect-token',
            'credentials' => ['system_user_token' => 'DISCONNECT_TOKEN'],
        ]);

        ChannelAccount::create([
            'workspace_id' => $ws->id,
            'channel' => 'whatsapp',
            'status' => 'active',
            'display_name' => 'Support',
            'phone_number_id' => 'PHONE_DISCONNECT',
            'business_account_id' => $waba->waba_id,
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'graph.facebook.com/*/WABA_DISCONNECT/subscribed_apps*' => \Illuminate\Support\Facades\Http::response(['success' => true]),
            'graph.facebook.com/*' => \Illuminate\Support\Facades\Http::response(['success' => true]),
        ]);

        $this->actingAs($user)
            ->delete(route('client.whatsapp.setup.destroy', $waba))
            ->assertRedirect();

        \Illuminate\Support\Facades\Http::assertSent(
            fn ($request) => $request->method() === 'DELETE'
                && str_contains($request->url(), '/WABA_DISCONNECT/subscribed_apps')
        );

        $this->assertDatabaseMissing('whatsapp_business_accounts', ['id' => $waba->id]);
        $this->assertDatabaseMissing('channel_accounts', ['business_account_id' => 'WABA_DISCONNECT']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'whatsapp.disconnected']);
    }

    private function openWhatsappWindow(Conversation $conversation): void
    {
        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => 'hi',
            'status' => 'delivered',
            'sent_by' => 'human',
            'sent_at' => now(),
        ]);
    }
}
