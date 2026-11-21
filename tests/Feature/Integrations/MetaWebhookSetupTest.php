<?php

namespace Tests\Feature\Integrations;

use App\Models\AdminUser;
use App\Models\Permission;
use App\Modules\Integrations\Models\IntegrationConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaWebhookSetupTest extends TestCase
{
    use RefreshDatabase;

    private const APP_ID = '3072642826417248';

    private const APP_SECRET = 'd72c1934fb30a2656650ff3d5fbf663d';

    private function seedMeta(array $credentials): IntegrationConfig
    {
        return IntegrationConfig::create([
            'provider' => 'meta_app',
            'label' => 'Meta App',
            'mode' => 'live',
            'enabled' => true,
            'credentials' => $credentials,
        ]);
    }

    private function integrationsAdmin(): AdminUser
    {
        $admin = $this->createSuperAdmin();

        $permission = Permission::firstOrCreate(
            ['key' => 'manage_integrations'],
            ['name' => 'Manage Integrations', 'category' => 'general']
        );

        $admin->roles()->first()?->permissions()->syncWithoutDetaching([$permission->id]);
        $admin->unsetRelation('roles');

        return $admin;
    }

    #[Test]
    public function edit_page_exposes_the_derived_whatsapp_verify_token(): void
    {
        $this->seedMeta([
            'app_id' => self::APP_ID,
            'app_secret' => self::APP_SECRET,
            'verify_token' => 'meta-social-token',
        ]);

        $expected = hash('sha256', self::APP_ID.self::APP_SECRET.'wh_global_verify');

        $this->actingAs($this->integrationsAdmin(), 'admin')
            ->get(route('admin.integrations.edit', 'meta_app'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Integrations/Edit')
                ->where('metaWebhook.verifyToken', $expected)
                ->where('metaWebhook.whatsappUrl', route('webhooks.whatsapp.global.receive'))
                ->where('metaWebhook.socialUrl', url('/webhooks/meta/meta-social-token'))
            );
    }

    #[Test]
    public function verify_token_is_absent_until_app_credentials_exist(): void
    {
        $this->actingAs($this->integrationsAdmin(), 'admin')
            ->get(route('admin.integrations.edit', 'meta_app'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metaWebhook.verifyToken', null)
                ->where('metaWebhook.socialUrl', null)
            );
    }

    #[Test]
    public function non_meta_integrations_do_not_receive_meta_webhook_props(): void
    {
        $this->actingAs($this->integrationsAdmin(), 'admin')
            ->get(route('admin.integrations.edit', 'qdrant'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('metaWebhook', null));
    }
}
