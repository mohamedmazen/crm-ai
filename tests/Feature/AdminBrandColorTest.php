<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBrandColorTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_light_brand_colours(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin, 'admin')->put(route('admin.settings.general.update'), [
            'primary_color' => '#F9A61A',
            'secondary_color' => '#B07436',
        ]);

        // Text on solid brand surfaces adapts (see BrandPalette::onBrand), so a
        // light primary no longer has to be rejected to stay readable.
        $response->assertSessionHasNoErrors();
        $this->assertSame('#F9A61A', SystemSetting::get('primary_color'));
        $this->assertSame('#B07436', SystemSetting::get('secondary_color'));
    }

    public function test_malformed_colour_still_fails_the_hex_rule(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->from(route('admin.settings.index'))
            ->actingAs($admin, 'admin')
            ->put(route('admin.settings.general.update'), [
                'primary_color' => 'not-a-colour',
            ]);

        $response->assertSessionHasErrors('primary_color');
    }
}