<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminBrandColorGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_brand_colours_that_white_text_can_sit_on(): void
    {
        $admin = $this->createSuperAdmin();

        $response = $this->actingAs($admin, 'admin')->put(route('admin.settings.general.update'), [
            'primary_color' => '#467235',
            'secondary_color' => '#283f24',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame('#467235', SystemSetting::get('primary_color'));
        $this->assertSame('#283f24', SystemSetting::get('secondary_color'));
    }

    public function test_primary_colour_too_light_for_white_text_is_rejected(): void
    {
        $admin = $this->createSuperAdmin();
        SystemSetting::set('primary_color', '#467235', false, 'general');

        $response = $this->from(route('admin.settings.index'))
            ->actingAs($admin, 'admin')
            ->put(route('admin.settings.general.update'), [
                'primary_color' => '#ffdd00',
            ]);

        $response->assertRedirect(route('admin.settings.index'));
        $response->assertSessionHasErrors('primary_color');
        $this->assertSame('#467235', SystemSetting::get('primary_color'));
    }

    public function test_secondary_colour_too_light_for_white_text_is_rejected(): void
    {
        $admin = $this->createSuperAdmin();
        SystemSetting::set('secondary_color', '#283f24', false, 'general');

        $response = $this->from(route('admin.settings.index'))
            ->actingAs($admin, 'admin')
            ->put(route('admin.settings.general.update'), [
                'secondary_color' => '#f5f5f5',
            ]);

        $response->assertRedirect(route('admin.settings.index'));
        $response->assertSessionHasErrors('secondary_color');
        $this->assertSame('#283f24', SystemSetting::get('secondary_color'));
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
