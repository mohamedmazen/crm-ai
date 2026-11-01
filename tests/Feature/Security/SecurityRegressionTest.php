<?php

namespace Tests\Feature\Security;

use App\Models\AdminUser;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Policies\ClientPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Regression coverage for the remaining security-audit findings: 2FA material
 * must never serialize or be mass-assignable, impersonation needs its own
 * permission, brute-force-prone endpoints must stay throttled, health probes
 * must be closed on a production host, and the infrastructure defaults must
 * fail safe.
 */
class SecurityRegressionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<string>
     */
    private function routeMiddleware(string $uri, string $method = 'POST'): array
    {
        foreach (Route::getRoutes() as $route) {
            if (
                ltrim($route->uri(), '/') === ltrim($uri, '/')
                && in_array($method, $route->methods(), true)
            ) {
                return $route->gatherMiddleware();
            }
        }

        $this->fail("Route [{$method} {$uri}] is not registered.");
    }

    // -------------------------------------------------------------------------
    // 2FA material
    // -------------------------------------------------------------------------

    #[Test]
    public function two_factor_secrets_are_not_serialized_or_mass_assignable(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => 'super-secret',
            'two_factor_recovery_codes' => ['code-1', 'code-2'],
        ]);

        $this->assertArrayNotHasKey('two_factor_secret', $user->toArray());
        $this->assertArrayNotHasKey('two_factor_recovery_codes', $user->toArray());

        foreach (['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'] as $field) {
            $this->assertNotContains($field, $user->getFillable(), "[{$field}] must not be mass-assignable.");
        }

        // Mass assignment must not be able to overwrite the stored secret.
        $user->fill(['two_factor_secret' => 'attacker-controlled']);
        $this->assertSame('super-secret', $user->two_factor_secret);
    }

    // -------------------------------------------------------------------------
    // Impersonation
    // -------------------------------------------------------------------------

    #[Test]
    public function impersonation_policy_requires_the_dedicated_permission(): void
    {
        $client = Client::create([
            'name' => 'Acme',
            'email' => 'acme@example.com',
            'status' => Client::STATUS_ACTIVE,
        ]);

        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['key' => 'support_test', 'name' => 'Support Test', 'description' => 'Limited']);
        $permission = Permission::firstOrCreate(
            ['key' => 'view_clients'],
            ['name' => 'View Clients', 'category' => 'Clients'],
        );
        $role->permissions()->attach($permission->id);
        $admin->roles()->attach($role->id);

        $policy = new ClientPolicy;

        $this->assertTrue($admin->hasPermissionTo('view_clients'));
        $this->assertFalse($policy->impersonate($admin, $client));

        $superAdmin = $this->createSuperAdmin();
        $this->assertTrue($policy->impersonate($superAdmin, $client));
    }

    #[Test]
    public function an_admin_with_only_view_clients_cannot_start_impersonation(): void
    {
        $client = Client::create([
            'name' => 'Acme',
            'email' => 'acme@example.com',
            'status' => Client::STATUS_ACTIVE,
        ]);

        $admin = AdminUser::factory()->create(['status' => AdminUser::STATUS_ACTIVE]);
        $role = Role::create(['key' => 'support_test_2', 'name' => 'Support Test 2', 'description' => 'Limited']);
        $permission = Permission::firstOrCreate(
            ['key' => 'view_clients'],
            ['name' => 'View Clients', 'category' => 'Clients'],
        );
        $role->permissions()->attach($permission->id);
        $admin->roles()->attach($role->id);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.clients.impersonate', $client))
            ->assertForbidden();

        $this->assertNull(session('impersonating'));
    }

    // -------------------------------------------------------------------------
    // Rate limiting
    // -------------------------------------------------------------------------

    #[Test]
    public function authentication_endpoints_are_rate_limited(): void
    {
        foreach (['login', 'register', 'auth/firebase'] as $uri) {
            $this->assertContains(
                'throttle:10,1',
                $this->routeMiddleware($uri),
                "[POST /{$uri}] must be throttled.",
            );
        }
    }

    #[Test]
    public function two_factor_and_contact_endpoints_are_rate_limited(): void
    {
        $this->assertContains('throttle:5,1', $this->routeMiddleware('two-factor-challenge'));
        $this->assertContains('throttle:5,1', $this->routeMiddleware('contact'));
    }

    #[Test]
    public function health_endpoints_are_rate_limited(): void
    {
        foreach (['healthz', 'healthz/db', 'healthz/redis', 'healthz/queue'] as $uri) {
            $this->assertContains(
                'throttle:30,1',
                $this->routeMiddleware($uri, 'GET'),
                "[GET /{$uri}] must be throttled.",
            );
        }
    }

    // -------------------------------------------------------------------------
    // Health probe gating
    // -------------------------------------------------------------------------

    #[Test]
    public function health_probes_are_closed_in_production_without_a_token(): void
    {
        config(['app.healthz_token' => null]);
        app()->detectEnvironment(fn () => 'production');

        try {
            $this->getJson('/healthz')->assertStatus(401);
            $this->getJson('/healthz/db')->assertStatus(401);
        } finally {
            app()->detectEnvironment(fn () => 'testing');
        }
    }

    #[Test]
    public function health_probes_require_the_bearer_token_when_configured(): void
    {
        config(['app.healthz_token' => 'health-token']);

        $this->getJson('/healthz')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer wrong-token'])->getJson('/healthz')->assertStatus(401);
        $this->withHeaders(['Authorization' => 'Bearer health-token'])->getJson('/healthz')->assertOk();
    }

    #[Test]
    public function health_probes_stay_open_outside_production_when_no_token_is_configured(): void
    {
        config(['app.healthz_token' => null]);

        $this->getJson('/healthz')
            ->assertOk()
            ->assertJsonPath('status', 'ok');
    }

    // -------------------------------------------------------------------------
    // Infrastructure defaults
    // -------------------------------------------------------------------------

    #[Test]
    public function no_proxy_is_trusted_by_default(): void
    {
        $this->assertArrayHasKey('proxies', config('trustedproxy'));
        $this->assertNull(
            config('trustedproxy.proxies'),
            'X-Forwarded-* headers must be ignored unless TRUSTED_PROXIES is configured.',
        );
    }

    #[Test]
    public function api_tokens_have_a_finite_lifetime(): void
    {
        $expiration = config('sanctum.expiration');

        $this->assertNotNull($expiration);
        $this->assertIsInt($expiration);
        $this->assertGreaterThan(0, $expiration);
    }
}
