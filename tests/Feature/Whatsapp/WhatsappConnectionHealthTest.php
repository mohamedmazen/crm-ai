<?php

namespace Tests\Feature\Whatsapp;

use App\Modules\Integrations\Models\IntegrationConfig;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\WhatsappConnectionHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WhatsappConnectionHealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function waba(array $attributes = []): WhatsappBusinessAccount
    {
        $ctx = $this->createWorkspaceContext();

        return WhatsappBusinessAccount::factory()->create(array_merge([
            'workspace_id' => $ctx['workspace']->id,
            'webhook_verify_token' => 'health-token-'.fake()->unique()->uuid(),
        ], $attributes));
    }

    #[Test]
    public function healthy_account_reports_ok(): void
    {
        $health = app(WhatsappConnectionHealth::class)->for($this->waba());

        $this->assertSame('ok', $health['state']);
        $this->assertNull($health['reason']);
    }

    #[Test]
    public function expired_token_requires_reauthorization(): void
    {
        $health = app(WhatsappConnectionHealth::class)->for($this->waba([
            'token_expires_at' => now()->subDay(),
        ]));

        $this->assertSame('reauth_required', $health['state']);
        $this->assertSame('token_expired', $health['reason']);
        $this->assertNotNull($health['token_expires_at']);
    }

    #[Test]
    public function token_expiring_within_a_week_needs_attention(): void
    {
        $health = app(WhatsappConnectionHealth::class)->for($this->waba([
            'token_expires_at' => now()->addDays(3),
        ]));

        $this->assertSame('needs_attention', $health['state']);
        $this->assertSame('token_expiring', $health['reason']);
        $this->assertLessThanOrEqual(3, $health['days_until_expiry']);
    }

    #[Test]
    public function account_without_a_token_requires_reauthorization(): void
    {
        $health = app(WhatsappConnectionHealth::class)->for($this->waba([
            'credentials' => [],
        ]));

        $this->assertSame('reauth_required', $health['state']);
        $this->assertSame('no_token', $health['reason']);
    }

    #[Test]
    public function meta_error_is_surfaced_as_an_error_state(): void
    {
        $health = app(WhatsappConnectionHealth::class)->for($this->waba([
            'status' => 'error',
            'last_error_at' => now()->subMinutes(5),
            'last_error_message' => 'Token revoked by Meta.',
        ]));

        $this->assertSame('error', $health['state']);
        $this->assertSame('connection_error', $health['reason']);
        $this->assertSame('Token revoked by Meta.', $health['message']);
    }

    #[Test]
    public function health_command_records_expiry_and_flags_invalid_tokens(): void
    {
        IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => [
                'app_id' => 'app-id',
                'app_secret' => 'app-secret',
                'verify_token' => 'verify',
            ],
        ]);

        $valid = $this->waba([
            'waba_id' => 'WABA_HEALTH_VALID',
            'webhook_verify_token' => 'health-valid',
            'credentials' => ['system_user_token' => 'VALID_TOKEN'],
        ]);

        $invalid = $this->waba([
            'waba_id' => 'WABA_HEALTH_INVALID',
            'webhook_verify_token' => 'health-invalid',
            'credentials' => ['system_user_token' => 'INVALID_TOKEN'],
        ]);

        $expiresAt = Carbon::now()->addDays(30)->timestamp;

        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::sequence()
                ->push(['data' => ['is_valid' => true, 'expires_at' => $expiresAt, 'scopes' => []]])
                ->push(['data' => [
                    'is_valid' => false,
                    'expires_at' => 0,
                    'error' => ['message' => 'Session has expired'],
                ]]),
        ]);

        $this->artisan('whatsapp:check-connection-health')->assertExitCode(0);

        $valid->refresh();
        $this->assertNotNull($valid->token_expires_at);
        $this->assertSame($expiresAt, $valid->token_expires_at->timestamp);
        $this->assertNotNull($valid->token_checked_at);
        $this->assertNull($valid->last_error_code);

        $invalid->refresh();
        $this->assertSame('error', $invalid->status);
        $this->assertSame('token_invalid', $invalid->last_error_code);
        $this->assertSame('Session has expired', $invalid->last_error_message);
        $this->assertNotNull($invalid->last_error_at);
        $this->assertSame('reauth_required', app(WhatsappConnectionHealth::class)->for($invalid)['state']);
    }
}
