<?php

namespace Tests\Feature\Broadcasting;

use App\Modules\Broadcasting\Models\SmsProviderConfig;
use App\Modules\Broadcasting\Services\Sms\CequensDriver;
use App\Modules\Broadcasting\Services\Sms\InfobipDriver;
use App\Modules\Broadcasting\Services\Sms\MsegatDriver;
use App\Modules\Broadcasting\Services\Sms\SmsDriverManager;
use App\Modules\Broadcasting\Services\Sms\SmsMisrDriver;
use App\Modules\Broadcasting\Services\Sms\TaqnyatDriver;
use App\Modules\Broadcasting\Services\Sms\UnifonicDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class SmsProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_driver_manager_builds_each_new_provider(): void
    {
        $this->assertInstanceOf(SmsMisrDriver::class, SmsDriverManager::build('sms_misr', []));
        $this->assertInstanceOf(CequensDriver::class, SmsDriverManager::build('cequens', []));
        $this->assertInstanceOf(UnifonicDriver::class, SmsDriverManager::build('unifonic', []));
        $this->assertInstanceOf(TaqnyatDriver::class, SmsDriverManager::build('taqnyat', []));
        $this->assertInstanceOf(MsegatDriver::class, SmsDriverManager::build('msegat', []));
        $this->assertInstanceOf(InfobipDriver::class, SmsDriverManager::build('infobip', []));
    }

    public function test_driver_manager_rejects_unknown_provider(): void
    {
        $this->expectException(RuntimeException::class);

        SmsDriverManager::build('not-a-provider', []);
    }

    public function test_sms_gateways_page_lists_providers_grouped_by_region(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $user->update(['workspace_id' => $workspace->id]);

        $this->actingAs($user->fresh())
            ->get(route('client.sms-gateways.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Broadcasting/SmsProviders/Index')
                ->where('providers', fn ($providers) => collect($providers)->contains('provider', 'sms_misr')
                    && collect($providers)->firstWhere('provider', 'cequens')['region'] === 'gcc'
                    && collect($providers)->firstWhere('provider', 'taqnyat')['region'] === 'gcc'
                    && collect($providers)->firstWhere('provider', 'infobip')['region'] === 'global'
                )
            );
    }

    public function test_sms_gateway_update_persists_new_provider_credentials(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $user->update(['workspace_id' => $workspace->id]);

        $this->actingAs($user->fresh())
            ->put(route('client.sms-gateways.update', 'taqnyat'), [
                'credentials' => ['token' => 'tok', 'sender' => 'Brand'],
                'default' => true,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('sms_provider_configs', [
            'workspace_id' => $workspace->id,
            'provider' => 'taqnyat',
            'default' => true,
        ]);
    }

    public function test_workspace_sender_override_replaces_the_credential_sender(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        SmsProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'twilio',
            'credentials' => ['account_sid' => 'AC1', 'auth_token' => 'tok', 'from_number' => '+10000000000'],
            'sender_id' => '+19999999999',
            'default' => true,
        ]);

        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);

        SmsDriverManager::forWorkspace($workspace->id)->send('+16175551234', 'Hi');

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.twilio.com')
            && $request->data()['From'] === '+19999999999');
    }

    public function test_for_workspace_uses_a_non_default_workspace_config(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        SmsProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'taqnyat',
            'credentials' => ['token' => 'tok', 'sender' => 'Brand'],
            'default' => false,
        ]);

        $this->assertInstanceOf(TaqnyatDriver::class, SmsDriverManager::forWorkspace($workspace->id));
    }
}
