<?php

namespace Tests\Feature\Inbox;

use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ManualChannelConnectTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ctx = $this->createWorkspaceContext();
        Queue::fake();
    }

    /* ─────────────────── WhatsApp ─────────────────── */

    private function fakeWhatsappGraph(): void
    {
        Http::fake([
            'graph.facebook.com/*/111222333/phone_numbers*' => Http::response([
                'data' => [[
                    'id' => '999888777',
                    'display_phone_number' => '+1 555-0100',
                    'verified_name' => 'Test Biz',
                ]],
            ]),
            'graph.facebook.com/*/999888777/register' => Http::response(['success' => true]),
            'graph.facebook.com/*/999888777*' => Http::response([
                'id' => '999888777',
                'display_phone_number' => '+1 555-0100',
                'verified_name' => 'Test Biz',
                'quality_rating' => 'GREEN',
            ]),
            'graph.facebook.com/*/111222333/subscribed_apps' => Http::response(['success' => true]),
            'graph.facebook.com/*/111222333*' => Http::response([
                'id' => '111222333',
                'name' => 'Manual WABA',
                'currency' => 'USD',
                'timezone_id' => '1',
            ]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);
    }

    public function test_whatsapp_manual_connect_creates_waba_and_channel_account(): void
    {
        $this->fakeWhatsappGraph();

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'MANUAL_TOKEN_123',
        ]);

        $response->assertOk()
            ->assertJson(['success' => true, 'waba_id' => '111222333', 'phone_count' => 1])
            ->assertJsonStructure(['webhook_url', 'webhook_verify_token']);

        $waba = WhatsappBusinessAccount::where('waba_id', '111222333')->first();
        $this->assertNotNull($waba);
        $this->assertSame($this->ctx['workspace']->id, $waba->workspace_id);
        // encrypted:array cast must round-trip the pasted token
        $this->assertSame('MANUAL_TOKEN_123', $waba->credentials['system_user_token']);
        $this->assertSame('manual', $waba->credentials['token_source']);
        $this->assertSame('manual', $waba->meta_json['connected_via']);

        $this->assertDatabaseHas('channel_accounts', [
            'workspace_id' => $this->ctx['workspace']->id,
            'channel' => 'whatsapp',
            'phone_number_id' => '999888777',
            'business_account_id' => '111222333',
            'status' => 'active',
        ]);
    }

    public function test_whatsapp_manual_connect_rejects_invalid_token(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => ['message' => 'Invalid OAuth access token.'],
            ], 401),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'BAD_TOKEN',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Invalid OAuth access token', $response->json('message'));
        $this->assertDatabaseMissing('whatsapp_business_accounts', ['waba_id' => '111222333']);
    }

    public function test_whatsapp_manual_connect_survives_business_level_field_rejection(): void
    {
        // `currency` / `timezone_id` need business_management; WhatsApp-only
        // tokens answer "(#200) Requires business_management permission".
        // Connecting must ignore that enrichment failure instead of aborting.
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/111222333/phone_numbers')) {
                return Http::response(['data' => [[
                    'id' => '999888777',
                    'display_phone_number' => '+1 555-0100',
                    'verified_name' => 'Test Biz',
                ]]]);
            }

            if (str_contains($url, '/111222333/subscribed_apps')) {
                return Http::response(['success' => true]);
            }

            if (str_contains($url, '/999888777')) {
                return Http::response([
                    'id' => '999888777',
                    'display_phone_number' => '+1 555-0100',
                    'verified_name' => 'Test Biz',
                ]);
            }

            if (str_contains($url, '/111222333')) {
                if (str_contains($url, 'currency')) {
                    return Http::response(['error' => [
                        'message' => '(#200) Requires business_management permission to manage the object',
                        'code' => 200,
                    ]], 400);
                }

                return Http::response(['id' => '111222333', 'name' => 'Manual WABA']);
            }

            return Http::response(['success' => true]);
        });

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'MANUAL_TOKEN_123',
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'waba_id' => '111222333',
            'name' => 'Manual WABA',
            'phone_count' => 1,
        ]);

        $waba = WhatsappBusinessAccount::where('waba_id', '111222333')->sole();
        $this->assertSame('Manual WABA', $waba->meta_json['display_name']);
        $this->assertNull($waba->meta_json['timezone_id']);
        $this->assertNull($waba->meta_json['currency']);
    }

    public function test_whatsapp_manual_connect_falls_back_to_phone_numbers_when_waba_node_is_restricted(): void
    {
        // Tokens that can manage WhatsApp assets but not read the WABA node get
        // "(#200) Requires business_management permission". The phone numbers
        // endpoint still proves the ID is a WhatsApp Business Account.
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/111222333/phone_numbers')) {
                return Http::response(['data' => [[
                    'id' => '999888777',
                    'display_phone_number' => '+1 555-0100',
                    'verified_name' => 'Restricted WABA',
                ]]]);
            }

            if (str_contains($url, '/111222333/subscribed_apps')) {
                return Http::response(['success' => true]);
            }

            if (str_contains($url, '/999888777')) {
                return Http::response([
                    'id' => '999888777',
                    'display_phone_number' => '+1 555-0100',
                    'verified_name' => 'Restricted WABA',
                ]);
            }

            if (str_contains($url, '/111222333')) {
                return Http::response(['error' => [
                    'message' => '(#200) Requires business_management permission to manage the object',
                    'code' => 200,
                ]], 400);
            }

            return Http::response(['success' => true]);
        });

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'MANUAL_TOKEN_123',
        ]);

        $response->assertOk()->assertJson([
            'success' => true,
            'waba_id' => '111222333',
            'name' => 'Restricted WABA',
            'phone_count' => 1,
        ]);

        $this->assertDatabaseHas('whatsapp_business_accounts', [
            'waba_id' => '111222333',
            'workspace_id' => $this->ctx['workspace']->id,
        ]);
    }

    public function test_whatsapp_manual_connect_rejects_an_id_that_is_not_a_waba(): void
    {
        // A Business Portfolio ID / phone number ID fails both the WABA node and
        // the phone_numbers probe, so no account is created.
        Http::fake([
            'graph.facebook.com/*/111222333*' => Http::response(['error' => [
                'message' => '(#200) Requires business_management permission to manage the object',
                'code' => 200,
            ]], 400),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'MANUAL_TOKEN_123',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('business_management', $response->json('message'));
        $this->assertDatabaseMissing('whatsapp_business_accounts', ['waba_id' => '111222333']);
    }

    public function test_whatsapp_manual_connect_rejects_a_business_portfolio_id(): void
    {
        // A Business Portfolio ID answers id/name like a WABA, but has no
        // phone_numbers edge. That must be rejected before an account is made.
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/111222333/phone_numbers')) {
                return Http::response(['error' => [
                    'message' => '(#100) Tried accessing nonexisting field (phone_numbers)',
                    'code' => 100,
                ]], 400);
            }

            if (str_contains($url, '/111222333/owned_whatsapp_business_accounts')) {
                return Http::response(['data' => [
                    ['id' => '555000111', 'name' => 'Real WABA'],
                ]]);
            }

            if (str_contains($url, '/111222333/client_whatsapp_business_accounts')) {
                return Http::response(['data' => []]);
            }

            if (str_contains($url, '/111222333')) {
                return Http::response(['id' => '111222333', 'name' => 'Acme Business Portfolio']);
            }

            return Http::response(['success' => true]);
        });

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'MANUAL_TOKEN_123',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Business Portfolio', $response->json('message'));
        $this->assertStringContainsString('555000111', $response->json('message'));
        $this->assertStringContainsString('Real WABA', $response->json('message'));
        $this->assertDatabaseMissing('whatsapp_business_accounts', ['waba_id' => '111222333']);
        $this->assertDatabaseMissing('channel_accounts', ['business_account_id' => '111222333']);
    }

    public function test_whatsapp_manual_connect_explains_a_phone_number_id(): void
    {
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/111222333/phone_numbers')
                || str_contains($url, '/111222333/owned_whatsapp_business_accounts')
                || str_contains($url, '/111222333/client_whatsapp_business_accounts')) {
                return Http::response(['error' => [
                    'message' => '(#100) Tried accessing nonexisting field (phone_numbers)',
                    'code' => 100,
                ]], 400);
            }

            if (str_contains($url, '/111222333')) {
                return Http::response(['id' => '111222333', 'display_phone_number' => '+1 555-0100']);
            }

            return Http::response(['success' => true]);
        });

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'MANUAL_TOKEN_123',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Phone Number ID', $response->json('message'));
        $this->assertDatabaseMissing('whatsapp_business_accounts', ['waba_id' => '111222333']);
    }

    public function test_whatsapp_manual_connect_warns_when_phone_numbers_cannot_be_listed(): void
    {
        // A WABA whose token cannot list phone numbers (management scope
        // missing) should still connect: sending may work even though syncing
        // numbers does not. The user gets a warning instead of a hard failure.
        Http::fake(function ($request) {
            $url = $request->url();

            if (str_contains($url, '/phone_numbers') || str_contains($url, '_whatsapp_business_accounts')) {
                return Http::response(['error' => [
                    'message' => '(#100) Tried accessing nonexisting field (phone_numbers)',
                    'code' => 100,
                ]], 400);
            }

            if (str_contains($url, 'display_phone_number')) {
                return Http::response(['error' => [
                    'message' => '(#100) Tried accessing nonexisting field (display_phone_number)',
                    'code' => 100,
                ]], 400);
            }

            if (str_contains($url, '/111222333')) {
                return Http::response(['id' => '111222333', 'name' => 'Manual WABA']);
            }

            return Http::response(['success' => true]);
        });

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'MANUAL_TOKEN_123',
        ]);

        $response->assertOk()->assertJson(['success' => true, 'name' => 'Manual WABA']);
        $this->assertStringContainsString('did not allow listing', (string) $response->json('webhook_warning'));
        $this->assertDatabaseHas('whatsapp_business_accounts', [
            'waba_id' => '111222333',
            'workspace_id' => $this->ctx['workspace']->id,
        ]);
    }

    public function test_whatsapp_manual_connect_rejects_waba_owned_by_other_workspace(): void
    {
        $this->fakeWhatsappGraph();

        $other = $this->createWorkspaceContext();
        WhatsappBusinessAccount::create([
            'workspace_id' => $other['workspace']->id,
            'waba_id' => '111222333',
            'credentials' => ['system_user_token' => 'x'],
            'webhook_verify_token' => str_repeat('a', 48),
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.whatsapp.setup.manual'), [
            'waba_id' => '111222333',
            'access_token' => 'MANUAL_TOKEN_123',
        ]);

        $response->assertStatus(409);
    }

    /* ─────────────────── Instagram ─────────────────── */

    public function test_instagram_manual_connect_creates_channel_account(): void
    {
        Http::fake([
            'graph.facebook.com/*/555000/subscribed_apps*' => Http::response(['success' => true]),
            'graph.facebook.com/*/555000*' => Http::response([
                'id' => '555000',
                'name' => 'Test Page',
                'access_token' => 'PAGE_TOKEN_ABC',
                'instagram_business_account' => ['id' => '17840000000', 'username' => 'testbiz'],
            ]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.setup.manual.instagram'), [
            'page_id' => '555000',
            'access_token' => 'USER_TOKEN',
        ]);

        $response->assertOk()->assertJson(['success' => true, 'connected' => 1, 'name' => 'testbiz']);

        $account = ChannelAccount::where('workspace_id', $this->ctx['workspace']->id)
            ->where('channel', 'instagram')
            ->first();
        $this->assertNotNull($account);
        $this->assertSame('testbiz', $account->display_name);
        // page token (from the page node) must be stored, decrypted via cast
        $this->assertSame('PAGE_TOKEN_ABC', $account->credentials['access_token']);
        $this->assertSame('17840000000', $account->credentials['instagram_account_id']);
        $this->assertSame('17840000000', $account->meta_json['instagram_page_id']);
        $this->assertSame('manual', $account->meta_json['connected_via']);
    }

    public function test_instagram_manual_connect_requires_linked_ig_account(): void
    {
        Http::fake([
            'graph.facebook.com/*/555000*' => Http::response([
                'id' => '555000',
                'name' => 'Test Page',
            ]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.setup.manual.instagram'), [
            'page_id' => '555000',
            'access_token' => 'USER_TOKEN',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('No Instagram Business account', $response->json('message'));
        $this->assertDatabaseMissing('channel_accounts', ['channel' => 'instagram']);
    }

    /* ─────────────────── Messenger ─────────────────── */

    public function test_messenger_manual_connect_accepts_page_token(): void
    {
        Http::fake([
            'graph.facebook.com/*/me*' => Http::response(['id' => '555000']),
            'graph.facebook.com/*/555000/subscribed_apps*' => Http::response(['success' => true]),
            'graph.facebook.com/*/555000*' => Http::response([
                'id' => '555000',
                'name' => 'Test Page',
                // page node fetched with its own token does not echo access_token
            ]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.setup.manual.messenger'), [
            'page_id' => '555000',
            'access_token' => 'PAGE_TOKEN_XYZ',
        ]);

        $response->assertOk()->assertJson(['success' => true, 'connected' => 1, 'name' => 'Test Page']);

        $account = ChannelAccount::where('workspace_id', $this->ctx['workspace']->id)
            ->where('channel', 'messenger')
            ->first();
        $this->assertNotNull($account);
        $this->assertSame('PAGE_TOKEN_XYZ', $account->credentials['page_access_token']);
        $this->assertSame('555000', $account->meta_json['page_id']);
        $this->assertSame('manual', $account->meta_json['connected_via']);
    }

    public function test_messenger_manual_connect_rejects_non_page_token(): void
    {
        Http::fake([
            // /me resolves to a user id, not the page — token is not a Page token
            'graph.facebook.com/*/me*' => Http::response(['id' => '42']),
            'graph.facebook.com/*/555000*' => Http::response([
                'id' => '555000',
                'name' => 'Test Page',
            ]),
            'graph.facebook.com/*' => Http::response(['success' => true]),
        ]);

        $response = $this->actingAs($this->ctx['user'])->postJson(route('client.inbox.setup.manual.messenger'), [
            'page_id' => '555000',
            'access_token' => 'USER_TOKEN',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Page Access Token', $response->json('message'));
        $this->assertDatabaseMissing('channel_accounts', ['channel' => 'messenger']);
    }

    /* ─────────────────── Validation / auth ─────────────────── */

    public function test_manual_endpoints_validate_required_fields(): void
    {
        $user = $this->ctx['user'];

        $this->actingAs($user)->postJson(route('client.whatsapp.setup.manual'), [])
            ->assertStatus(422)->assertJsonValidationErrors(['waba_id', 'access_token']);

        $this->actingAs($user)->postJson(route('client.inbox.setup.manual.instagram'), [])
            ->assertStatus(422)->assertJsonValidationErrors(['page_id', 'access_token']);

        $this->actingAs($user)->postJson(route('client.inbox.setup.manual.messenger'), [])
            ->assertStatus(422)->assertJsonValidationErrors(['page_id', 'access_token']);
    }

    public function test_guests_cannot_use_manual_endpoints(): void
    {
        $this->postJson(route('client.whatsapp.setup.manual'), [])->assertStatus(401);
        $this->postJson(route('client.inbox.setup.manual.instagram'), [])->assertStatus(401);
        $this->postJson(route('client.inbox.setup.manual.messenger'), [])->assertStatus(401);
    }
}
