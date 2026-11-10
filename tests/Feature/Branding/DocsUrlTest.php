<?php

namespace Tests\Feature\Branding;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The client sidebar's "Help Center" entry is driven entirely by the shared
 * `branding.docs_url` prop: when it is blank the entry is dropped from the nav.
 * A stock install has no SAAS_DOCS_URL and no admin setting, which used to
 * leave production without any Help Center at all even though the guides are
 * shipped in public/docs.
 */
class DocsUrlTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_help_center_falls_back_to_the_bundled_guides(): void
    {
        config(['saas.docs_url' => '']);

        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->get(route('client.dashboard', absolute: false))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.docs_url', '/docs/index.html')
            );

        $this->assertFileExists(public_path('docs/index.html'));
    }

    #[Test]
    public function an_environment_url_takes_precedence_over_the_bundled_guides(): void
    {
        config(['saas.docs_url' => 'https://help.example.com']);

        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->get(route('client.dashboard', absolute: false))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.docs_url', 'https://help.example.com')
            );
    }

    #[Test]
    public function the_admin_setting_takes_precedence_over_the_environment(): void
    {
        config(['saas.docs_url' => 'https://help.example.com']);

        SystemSetting::set('docs_url', 'https://docs.example.com', false, 'general');

        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->get(route('client.dashboard', absolute: false))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('branding.docs_url', 'https://docs.example.com')
            );
    }
}
