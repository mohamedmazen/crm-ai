<?php

namespace Tests\Feature\Security;

use App\Models\ClientRole;
use App\Models\User;
use App\Services\ClientAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientPermissionTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $slug, array $permissions = []): User
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();

        ClientRole::create([
            'client_id' => $client->id,
            'name' => 'Custom',
            'slug' => $slug,
            'permissions' => $permissions,
        ]);

        $admin->update(['client_role' => $slug]);

        return $admin->fresh();
    }

    #[Test]
    public function a_custom_role_only_reaches_the_modules_it_was_granted(): void
    {
        $user = $this->userWithRole('support-only', ['contacts.view']);

        $this->actingAs($user)->get(route('client.contacts.index'))->assertOk();

        $this->actingAs($user)->get(route('client.team.index'))->assertForbidden();
        $this->actingAs($user)->get(route('client.audit-log.index'))->assertForbidden();
    }

    #[Test]
    public function a_custom_role_cannot_write_without_the_write_permission(): void
    {
        $user = $this->userWithRole('reader', ['contacts.view']);

        $this->actingAs($user)
            ->post(route('client.contacts.store'), [
                'phone_e164' => '+201000000010',
                'first_name' => 'Blocked',
            ])
            ->assertForbidden();
    }

    #[Test]
    public function granting_write_permission_lets_the_role_through_to_the_controller(): void
    {
        $user = $this->userWithRole('editor', ['contacts.view', 'contacts.write']);

        $response = $this->actingAs($user)->post(route('client.contacts.store'), []);

        // Reaches validation (302) instead of being blocked by the middleware.
        $this->assertNotSame(403, $response->getStatusCode());
    }

    #[Test]
    public function a_write_request_on_a_read_only_module_maps_to_its_view_permission(): void
    {
        $access = app(ClientAccess::class);

        $this->assertSame('audit.view', $access->requiredPermissionFor('client.audit-log.index', 'GET'));
        $this->assertSame('audit.view', $access->requiredPermissionFor('client.audit-log.index', 'POST'));
    }

    #[Test]
    public function administrator_only_modules_stay_blocked_for_custom_roles(): void
    {
        // The matrix hides these modules, but a hand-crafted role must not get
        // past the controller's own administrator guard.
        $user = $this->userWithRole('auditor', ['audit.view']);

        $this->actingAs($user)->get(route('client.audit-log.index'))->assertForbidden();
        $this->actingAs($user)->get(route('client.settings.gdpr'))->assertForbidden();
    }

    #[Test]
    public function role_templates_never_grant_administrator_only_permissions(): void
    {
        $access = app(ClientAccess::class);
        $adminOnly = $access->adminOnlyKeys();

        $this->assertContains('team.view', $adminOnly);
        $this->assertContains('audit.view', $adminOnly);

        foreach ($access->catalogForUi()['templates'] as $template) {
            $this->assertSame([], array_values(array_intersect($template['permissions'], $adminOnly)));
        }
    }

    #[Test]
    public function an_unknown_role_slug_grants_nothing(): void
    {
        $user = $this->userWithRole('temp', ['contacts.view']);
        $user->update(['client_role' => 'deleted-while-assigned']);

        $user = $user->fresh();

        $this->assertSame([], app(ClientAccess::class)->permissionsFor($user));

        $this->actingAs($user)->get(route('client.contacts.index'))->assertForbidden();
        $this->actingAs($user)->get(route('client.dashboard'))->assertOk();
    }

    #[Test]
    public function built_in_roles_keep_their_existing_meaning(): void
    {
        ['user' => $staff] = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_STAFF]);
        ['user' => $viewer] = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_VIEWER]);
        ['user' => $admin] = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_ADMINISTRATOR]);

        $access = app(ClientAccess::class);

        $this->assertTrue($access->userCan($staff, 'contacts.write'));
        $this->assertFalse($access->userCan($staff, 'team.view'));
        $this->assertFalse($access->userCan($staff, 'audit.view'));

        $this->assertTrue($access->userCan($viewer, 'contacts.view'));
        $this->assertFalse($access->userCan($viewer, 'contacts.write'));

        $this->assertTrue($access->userCan($admin, 'team.write'));
        $this->assertTrue($access->userCan($admin, 'privacy.write'));
    }

    #[Test]
    public function a_permission_from_another_tenant_does_not_leak(): void
    {
        ['user' => $first, 'client' => $firstClient] = $this->createWorkspaceContext();
        ['user' => $second, 'client' => $secondClient] = $this->createWorkspaceContext([], [
            'client_role' => 'shared-slug',
        ]);

        // Same slug, different permissions in each tenant.
        ClientRole::create([
            'client_id' => $firstClient->id,
            'name' => 'Shared',
            'slug' => 'shared-slug',
            'permissions' => ['contacts.view', 'contacts.write'],
        ]);
        ClientRole::create([
            'client_id' => $secondClient->id,
            'name' => 'Shared',
            'slug' => 'shared-slug',
            'permissions' => ['contacts.view'],
        ]);

        $first->update(['client_role' => 'shared-slug']);
        $access = app(ClientAccess::class);

        $this->assertTrue($access->userCan($first->fresh(), 'contacts.write'));
        $this->assertFalse($access->userCan($second->fresh(), 'contacts.write'));
    }

    #[Test]
    public function every_client_route_is_owned_by_a_module_or_listed_as_public(): void
    {
        $access = app(ClientAccess::class);
        $unmapped = [];

        foreach (app('router')->getRoutes() as $route) {
            $name = $route->getName();

            if (! $name || (! str_starts_with($name, 'client.') && ! str_starts_with($name, 'reports.'))) {
                continue;
            }

            if ($access->requiredPermissionFor($name, 'GET') === ClientAccess::UNMAPPED) {
                $unmapped[] = $name;
            }
        }

        $this->assertSame([], $unmapped, 'These routes have no permission mapping: '.implode(', ', $unmapped));
    }

    #[Test]
    public function every_catalogued_permission_is_a_view_or_write_key(): void
    {
        $access = app(ClientAccess::class);

        foreach ($access->allPermissionKeys() as $key) {
            $this->assertMatchesRegularExpression('/^[a-z_]+\.(view|write)$/', $key);
        }

        $this->assertContains('inbox.view', $access->allPermissionKeys());
        $this->assertNotContains('audit.write', $access->allPermissionKeys());
    }
}
