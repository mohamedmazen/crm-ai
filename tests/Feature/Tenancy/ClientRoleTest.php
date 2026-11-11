<?php

namespace Tests\Feature\Tenancy;

use App\Models\ClientRole;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClientRoleTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_client_administrator_can_create_a_custom_role(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();

        $this->actingAs($admin)
            ->post(route('client.roles.store'), [
                'name' => 'Sales Agent',
                'description' => 'Owns the pipeline',
                'permissions' => ['leads.view', 'leads.write', 'contacts.view'],
            ])
            ->assertRedirect();

        $role = ClientRole::where('client_id', $client->id)->first();

        $this->assertNotNull($role);
        $this->assertSame('sales-agent', $role->slug);
        $this->assertSame(['leads.view', 'leads.write', 'contacts.view'], $role->permissions);
    }

    #[Test]
    public function identical_role_names_still_get_unique_slugs(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();

        $this->actingAs($admin)->post(route('client.roles.store'), [
            'name' => 'Support',
            'permissions' => ['inbox.view'],
        ])->assertRedirect();

        $this->actingAs($admin)->post(route('client.roles.store'), [
            'name' => 'Support',
            'permissions' => ['inbox.write'],
        ])->assertRedirect();

        $slugs = ClientRole::where('client_id', $client->id)->orderBy('id')->pluck('slug')->all();

        $this->assertSame(['support', 'support-2'], $slugs);
    }

    #[Test]
    public function an_arabic_role_name_produces_a_usable_slug(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();

        $this->actingAs($admin)->post(route('client.roles.store'), [
            'name' => 'مشرف',
            'permissions' => ['inbox.view'],
        ])->assertRedirect();

        $slug = ClientRole::where('client_id', $client->id)->value('slug');

        $this->assertNotEmpty($slug);
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);

        $this->actingAs($admin)->post(route('client.roles.store'), [
            'name' => 'محاسب',
            'permissions' => ['invoices.view'],
        ])->assertRedirect();

        $slugs = ClientRole::where('client_id', $client->id)->orderBy('id')->pluck('slug')->all();

        $this->assertCount(2, $slugs);
        $this->assertNotSame($slugs[0], $slugs[1]);
    }

    #[Test]
    public function unknown_permissions_are_rejected(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();

        $this->actingAs($admin)
            ->post(route('client.roles.store'), [
                'name' => 'Sneaky',
                'permissions' => ['inbox.view', 'billing.destroy'],
            ])
            ->assertSessionHasErrors('permissions.1');

        $this->assertSame(0, ClientRole::where('client_id', $client->id)->count());
    }

    #[Test]
    public function a_role_can_be_updated_within_its_tenant(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();

        $role = ClientRole::create([
            'client_id' => $client->id,
            'name' => 'Support',
            'slug' => 'support',
            'permissions' => ['inbox.view'],
        ]);

        $this->actingAs($admin)
            ->put(route('client.roles.update', $role), [
                'name' => 'Support Lead',
                'permissions' => ['inbox.view', 'inbox.write'],
            ])
            ->assertRedirect();

        $role->refresh();

        $this->assertSame('Support Lead', $role->name);
        $this->assertSame(['inbox.view', 'inbox.write'], $role->permissions);
        // The slug is stable: users keep their assignment after a rename.
        $this->assertSame('support', $role->slug);
    }

    #[Test]
    public function a_role_from_another_tenant_cannot_be_edited_or_deleted(): void
    {
        ['user' => $admin] = $this->createWorkspaceContext();
        ['client' => $otherClient] = $this->createWorkspaceContext();

        $foreignRole = ClientRole::create([
            'client_id' => $otherClient->id,
            'name' => 'Foreign',
            'slug' => 'foreign',
            'permissions' => ['inbox.view'],
        ]);

        $this->actingAs($admin)
            ->put(route('client.roles.update', $foreignRole), [
                'name' => 'Hijacked',
                'permissions' => ['inbox.view'],
            ])
            ->assertNotFound();

        $this->actingAs($admin)
            ->delete(route('client.roles.destroy', $foreignRole))
            ->assertNotFound();

        $this->assertDatabaseHas('client_roles', [
            'id' => $foreignRole->id,
            'name' => 'Foreign',
        ]);
    }

    #[Test]
    public function a_role_still_assigned_to_a_member_cannot_be_deleted(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();

        $role = ClientRole::create([
            'client_id' => $client->id,
            'name' => 'Accountant',
            'slug' => 'accountant',
            'permissions' => ['invoices.view'],
        ]);

        User::factory()->create([
            'role' => User::ROLE_CLIENT,
            'client_id' => $client->id,
            'client_role' => 'accountant',
            'status' => User::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin)
            ->delete(route('client.roles.destroy', $role))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('client_roles', ['id' => $role->id]);
    }

    #[Test]
    public function an_unused_custom_role_can_be_deleted(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();

        $role = ClientRole::create([
            'client_id' => $client->id,
            'name' => 'Temp',
            'slug' => 'temp',
            'permissions' => [],
        ]);

        $this->actingAs($admin)
            ->delete(route('client.roles.destroy', $role))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('client_roles', ['id' => $role->id]);
    }

    #[Test]
    public function a_long_role_name_produces_a_slug_that_fits_the_user_column(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();

        $this->actingAs($admin)->post(route('client.roles.store'), [
            'name' => str_repeat('long-name-', 12),
            'permissions' => ['inbox.view'],
        ])->assertRedirect();

        $slug = ClientRole::where('client_id', $client->id)->value('slug');

        $this->assertLessThanOrEqual(32, strlen($slug));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    #[Test]
    public function built_in_roles_are_listed_first_and_cannot_be_deleted(): void
    {
        ['user' => $admin, 'client' => $client] = $this->createWorkspaceContext();

        $custom = ClientRole::create([
            'client_id' => $client->id,
            'name' => 'Custom',
            'slug' => 'custom',
            'permissions' => ['inbox.view'],
        ]);

        $this->actingAs($admin)
            ->get(route('client.roles.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('client/Team/Roles')
                ->has('roles', 4)
                ->where('roles.0.slug', 'administrator')
                ->where('roles.0.system', true)
                ->where('roles.3.slug', 'custom')
                ->where('roles.3.id', $custom->id)
                ->where('roles.3.system', false)
            );
    }

    #[Test]
    public function managers_cannot_be_invited_with_a_role_from_another_tenant(): void
    {
        ['user' => $admin] = $this->createWorkspaceContext();
        ['client' => $otherClient] = $this->createWorkspaceContext();

        ClientRole::create([
            'client_id' => $otherClient->id,
            'name' => 'Foreign',
            'slug' => 'foreign',
            'permissions' => ['inbox.view'],
        ]);

        $this->actingAs($admin)
            ->post(route('client.invitations.store'), [
                'email' => Str::random(8).'@example.com',
                'client_role' => 'foreign',
            ])
            ->assertSessionHasErrors('client_role');
    }

    #[Test]
    public function an_invitation_for_a_deleted_role_falls_back_to_staff(): void
    {
        ['client' => $client] = $this->createWorkspaceContext();

        $invitation = Invitation::create([
            'client_id' => $client->id,
            'email' => 'late@example.com',
            'client_role' => 'deleted-role',
            'token' => Str::random(64),
            'expires_at' => now()->addDays(7),
        ]);

        $this->post(route('auth.invitations.accept', $invitation->token), [
            'name' => 'Late Joiner',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('client.dashboard'));

        $user = User::where('email', 'late@example.com')->firstOrFail();

        $this->assertSame($client->id, $user->client_id);
        $this->assertSame(User::CLIENT_ROLE_STAFF, $user->client_role);
    }
}
