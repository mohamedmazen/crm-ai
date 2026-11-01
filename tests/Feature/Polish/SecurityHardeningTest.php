<?php

namespace Tests\Feature\Polish;

use App\Models\Plan;
use App\Models\SystemSetting;
use App\Models\User;
use App\Rules\NotBreachedPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_health_endpoint_checks_database_cache_and_storage(): void
    {
        $this->get('/healthz')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'checks' => [
                    'database' => 'ok',
                    'cache' => 'ok',
                    'storage' => 'ok',
                ],
            ]);
    }

    #[Test]
    public function the_up_probe_reports_dependency_health(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
                'checks' => [
                    'database' => 'ok',
                    'cache' => 'ok',
                    'storage' => 'ok',
                ],
            ]);
    }

    #[Test]
    public function a_breached_password_is_rejected(): void
    {
        config(['security.breach_check' => true]);

        $password = 'correct-horse-battery-staple';
        $hash = strtoupper(sha1($password));

        Http::fake([
            'api.pwnedpasswords.com/range/*' => Http::response(
                substr($hash, 5).':42'."\r\n".'0000000000000000000000000000000000A:3',
                200,
            ),
        ]);

        $validator = Validator::make(
            ['password' => $password],
            ['password' => [new NotBreachedPassword]],
        );

        $this->assertTrue($validator->fails());
        $this->assertStringContainsString('data breach', $validator->errors()->first('password'));
    }

    #[Test]
    public function a_clean_password_passes(): void
    {
        config(['security.breach_check' => true]);

        $password = 'correct-horse-battery-staple';
        $hash = strtoupper(sha1($password));

        Http::fake([
            'api.pwnedpasswords.com/range/*' => Http::response('0000000000000000000000000000000000A:3', 200),
        ]);

        $validator = Validator::make(
            ['password' => $password],
            ['password' => [new NotBreachedPassword]],
        );

        $this->assertFalse($validator->fails());
        // Only the five-character hash prefix is sent to the API.
        Http::assertSent(fn ($request) => str_ends_with($request->url(), substr($hash, 0, 5)));
    }

    #[Test]
    public function the_breach_check_fails_open_when_the_api_is_unreachable(): void
    {
        config(['security.breach_check' => true]);

        Http::fake(fn () => throw new ConnectionException('HIBP is down'));

        $validator = Validator::make(
            ['password' => 'correct-horse-battery-staple'],
            ['password' => [new NotBreachedPassword]],
        );

        $this->assertFalse($validator->fails());
    }

    #[Test]
    public function the_ip_allowlist_blocks_unlisted_addresses_when_enabled(): void
    {
        SystemSetting::set('security.ip_allowlist_enabled', '1');
        SystemSetting::set('security.ip_allowlist', '10.0.0.0/8, 192.168.1.10');

        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    #[Test]
    public function the_ip_allowlist_allows_cidr_members(): void
    {
        SystemSetting::set('security.ip_allowlist_enabled', '1');
        SystemSetting::set('security.ip_allowlist', '10.0.0.0/8');

        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->withServerVariables(['REMOTE_ADDR' => '10.42.7.3'])
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    #[Test]
    public function the_ip_allowlist_is_ignored_when_disabled(): void
    {
        SystemSetting::set('security.ip_allowlist_enabled', '0');
        SystemSetting::set('security.ip_allowlist', '10.0.0.0/8');

        $admin = $this->createSuperAdmin();

        $this->actingAs($admin, 'admin')
            ->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->get(route('admin.dashboard'))
            ->assertOk();
    }

    #[Test]
    public function a_viewer_can_read_but_cannot_write(): void
    {
        ['user' => $viewer] = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_VIEWER]);

        $this->actingAs($viewer)
            ->get(route('client.dashboard'))
            ->assertOk();

        $this->actingAs($viewer)
            ->post(route('client.sales.quotes.store'), [])
            ->assertForbidden();
    }

    #[Test]
    public function a_viewer_can_still_update_their_own_preferences(): void
    {
        ['user' => $viewer] = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_VIEWER]);

        $this->actingAs($viewer)
            ->post(route('client.notifications.read-all'))
            ->assertRedirect();
    }

    #[Test]
    public function a_staff_member_is_not_subject_to_the_viewer_restriction(): void
    {
        ['user' => $staff, 'client' => $client] = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_STAFF]);

        $this->attachPlanToClient($client, Plan::factory()->create(['feature_flags' => ['quotes' => true]]));

        $response = $this->actingAs($staff)->post(route('client.sales.quotes.store'), []);

        // Reaches the controller's validation (302) instead of the viewer block.
        $this->assertNotSame(403, $response->getStatusCode());
    }
}
