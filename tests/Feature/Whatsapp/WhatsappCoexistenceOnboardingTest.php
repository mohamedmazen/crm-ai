<?php

namespace Tests\Feature\Whatsapp;

use App\Models\Plan;
use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappCoexistenceOnboardingTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '1234567890';

    private const APP_SECRET = 'test-app-secret';

    private const WABA_ID = 'WABA_COEX';

    private const PHONE_ID = 'PHONE_COEX';

    #[Test]
    public function coexistence_is_rejected_when_the_plan_does_not_include_it(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create(['feature_flags' => ['whatsapp_coexistence' => false]]);
        $this->attachPlanToClient($client, $plan);

        $this->actingAs($user)
            ->postJson(route('client.whatsapp.setup.embedded-signup'), [
                'code' => 'auth-code',
                'waba_id' => self::WABA_ID,
                'phone_number_id' => self::PHONE_ID,
                'coexistence' => true,
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('whatsapp_business_accounts', 0);
    }

    #[Test]
    public function coexistence_is_rejected_when_meta_does_not_report_the_number_on_the_business_app(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachCoexistencePlan($client);
        $this->seedMetaIntegration();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'oauth/access_token')) {
                return Http::response(['access_token' => 'LONG_LIVED_TOKEN']);
            }

            if (str_contains($url, '/'.self::PHONE_ID)) {
                return Http::response([
                    'id' => self::PHONE_ID,
                    'is_on_biz_app' => false,
                    'platform_type' => 'CLOUD_API',
                ]);
            }

            return Http::response(['id' => self::WABA_ID, 'name' => 'Coexistence WABA']);
        });

        $this->actingAs($user)
            ->postJson(route('client.whatsapp.setup.embedded-signup'), [
                'code' => 'auth-code',
                'waba_id' => self::WABA_ID,
                'phone_number_id' => self::PHONE_ID,
                'coexistence' => true,
                'session_event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('whatsapp_business_accounts', 0);
    }

    #[Test]
    public function a_verified_coexistence_number_is_saved_without_phone_registration(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachCoexistencePlan($client);
        $this->seedMetaIntegration();
        Queue::fake();

        Http::fake(function (Request $request) {
            $url = $request->url();

            if (str_contains($url, 'oauth/access_token')) {
                return Http::response(['access_token' => 'LONG_LIVED_TOKEN']);
            }

            if (str_contains($url, '/'.self::WABA_ID.'/phone_numbers')) {
                return Http::response(['data' => [[
                    'id' => self::PHONE_ID,
                    'display_phone_number' => '201000000000',
                    'verified_name' => 'Coexistence WABA',
                ]]]);
            }

            if (str_contains($url, 'is_on_biz_app')) {
                return Http::response([
                    'id' => self::PHONE_ID,
                    'is_on_biz_app' => true,
                    'platform_type' => 'CLOUD_API',
                ]);
            }

            if (str_contains($url, '/'.self::PHONE_ID)) {
                return Http::response([
                    'id' => self::PHONE_ID,
                    'display_phone_number' => '201000000000',
                    'verified_name' => 'Coexistence WABA',
                ]);
            }

            if (str_contains($url, '/'.self::WABA_ID)) {
                return Http::response(['id' => self::WABA_ID, 'name' => 'Coexistence WABA']);
            }

            return Http::response(['success' => true]);
        });

        $this->actingAs($user)
            ->postJson(route('client.whatsapp.setup.embedded-signup'), [
                'code' => 'auth-code',
                'waba_id' => self::WABA_ID,
                'phone_number_id' => self::PHONE_ID,
                'coexistence' => true,
                'session_event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
            ])
            ->assertOk()
            ->assertJsonPath('coexistence', true)
            ->assertJsonPath('history_sync_status', 'pending_consent');

        $waba = WhatsappBusinessAccount::where('workspace_id', $workspace->id)->sole();

        $this->assertTrue($waba->is_coexistence);
        $this->assertSame(WhatsappBusinessAccount::COEXISTENCE_ACTIVE, $waba->coexistence_status);
        $this->assertNotNull($waba->coexistence_linked_at);

        // Coexistence numbers are already registered in the WhatsApp Business app.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '/register'));

        // The app-global subscription must include the coexistence webhook fields.
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/subscriptions')
            && str_contains((string) ($request['fields'] ?? ''), 'smb_message_echoes')
            && str_contains((string) ($request['fields'] ?? ''), 'history'));
    }

    private function attachCoexistencePlan($client): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['whatsapp_coexistence' => true]]);
        $this->attachPlanToClient($client, $plan);
    }

    private function seedMetaIntegration(): void
    {
        IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'app_id' => self::APP_ID,
                'app_secret' => self::APP_SECRET,
            ],
        ]);
    }
}
