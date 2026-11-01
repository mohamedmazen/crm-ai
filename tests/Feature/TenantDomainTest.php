<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantDomainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'tenancy.enabled' => true,
            'tenancy.base_domain' => 'crm.test',
            'tenancy.central_domains' => ['localhost', '127.0.0.1'],
            'app.url' => 'http://crm.test',
        ]);
    }

    private function clientWithSubdomain(string $subdomain, array $attributes = []): Client
    {
        $client = Client::create(array_merge([
            'name' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'status' => Client::STATUS_ACTIVE,
            'subdomain' => $subdomain,
        ], $attributes));

        return $client;
    }

    #[Test]
    public function a_subdomain_host_resolves_the_matching_workspace(): void
    {
        $client = $this->clientWithSubdomain('acme');

        $this->get('http://acme.crm.test/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.id', $client->id)
                ->where('tenant.subdomain', 'acme')
            );
    }

    #[Test]
    public function a_custom_domain_resolves_the_matching_workspace(): void
    {
        $client = $this->clientWithSubdomain('acme', ['custom_domain' => 'crm.example.com']);

        $this->get('http://crm.example.com/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tenant.id', $client->id)
                ->where('tenant.custom_domain', 'crm.example.com')
            );
    }

    #[Test]
    public function the_workspace_branding_overrides_the_platform_defaults(): void
    {
        $this->clientWithSubdomain('acme', [
            'name' => 'Acme Trading',
            'tagline' => 'Trading made easy',
            'primary_color' => '#112233',
        ]);

        $this->get('http://acme.crm.test/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.app_name', 'Acme Trading')
                ->where('branding.app_tagline', 'Trading made easy')
                ->where('branding.primary_color', '#112233')
            );
    }

    #[Test]
    public function an_unknown_subdomain_returns_not_found(): void
    {
        $this->get('http://nobody.crm.test/login')->assertNotFound();
    }

    #[Test]
    public function a_reserved_subdomain_is_never_treated_as_a_workspace(): void
    {
        // Even if a row somehow exists, platform subdomains stay central.
        $this->clientWithSubdomain('admin');

        $this->get('http://admin.crm.test/login')->assertNotFound();
    }

    #[Test]
    public function the_central_host_is_left_untouched(): void
    {
        $this->clientWithSubdomain('acme');

        $this->get('http://crm.test/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tenant', null));
    }

    #[Test]
    public function an_inactive_workspace_domain_is_not_resolved(): void
    {
        $this->clientWithSubdomain('acme', ['status' => Client::STATUS_INACTIVE]);

        $this->get('http://acme.crm.test/login')->assertNotFound();
    }

    #[Test]
    public function tenant_resolution_can_be_disabled(): void
    {
        config(['tenancy.enabled' => false]);
        $this->clientWithSubdomain('acme');

        $this->get('http://acme.crm.test/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('tenant', null));
    }

    #[Test]
    public function a_user_cannot_open_another_workspaces_domain(): void
    {
        ['user' => $user] = $this->createWorkspaceContext([], [], ['name' => 'First']);
        $this->clientWithSubdomain('other');

        $path = route('client.dashboard', absolute: false);

        $this->actingAs($user)
            ->get('http://other.crm.test'.$path)
            ->assertForbidden();
    }

    #[Test]
    public function a_user_can_open_their_own_workspace_domain(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $client->update(['subdomain' => 'mine']);

        $path = route('client.dashboard', absolute: false);

        $this->actingAs($user)
            ->get('http://mine.crm.test'.$path)
            ->assertOk();
    }

    #[Test]
    public function a_client_administrator_can_save_their_domains(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.settings.update'), [
                'client_subdomain' => 'Acme-Team',
                'client_custom_domain' => 'https://CRM.Example.com/',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $client->refresh();

        $this->assertSame('acme-team', $client->subdomain);
        $this->assertSame('crm.example.com', $client->custom_domain);
    }

    #[Test]
    public function an_invalid_subdomain_is_rejected(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.settings.update'), ['client_subdomain' => 'bad_subdomain'])
            ->assertSessionHasErrors('client_subdomain');
    }

    #[Test]
    public function a_reserved_subdomain_is_rejected(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.settings.update'), ['client_subdomain' => 'admin'])
            ->assertSessionHasErrors('client_subdomain');
    }

    #[Test]
    public function a_subdomain_already_used_by_another_workspace_is_rejected(): void
    {
        $this->clientWithSubdomain('taken');
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.settings.update'), ['client_subdomain' => 'taken'])
            ->assertSessionHasErrors('client_subdomain');
    }

    #[Test]
    public function a_custom_domain_already_used_by_another_workspace_is_rejected(): void
    {
        $this->clientWithSubdomain('taken', ['custom_domain' => 'crm.example.com']);
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.settings.update'), ['client_custom_domain' => 'crm.example.com'])
            ->assertSessionHasErrors('client_custom_domain');
    }

    #[Test]
    public function a_malformed_custom_domain_is_rejected(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->put(route('client.settings.update'), ['client_custom_domain' => 'not a domain'])
            ->assertSessionHasErrors('client_custom_domain');
    }

    #[Test]
    public function a_staff_member_cannot_change_the_workspace_domains(): void
    {
        ['user' => $staff, 'client' => $client] = $this->createWorkspaceContext([], ['client_role' => User::CLIENT_ROLE_STAFF]);

        $this->actingAs($staff)
            ->put(route('client.settings.update'), ['client_subdomain' => 'staff-owned'])
            ->assertRedirect();

        $this->assertNull($client->fresh()->subdomain);
    }
}
