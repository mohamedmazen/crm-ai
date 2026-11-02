<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function seedCurrencies(): void
    {
        Currency::insert([
            ['code' => 'USD', 'symbol' => '$', 'decimals' => 2, 'exchange_rate' => 1, 'is_default' => true, 'enabled' => true],
            ['code' => 'EGP', 'symbol' => 'ج.م', 'decimals' => 2, 'exchange_rate' => 0.02, 'is_default' => false, 'enabled' => true],
            ['code' => 'SAR', 'symbol' => 'ر.س', 'decimals' => 2, 'exchange_rate' => 0.26666667, 'is_default' => false, 'enabled' => true],
            // Retired currencies stay in the table but must not be offered.
            ['code' => 'EUR', 'symbol' => '€', 'decimals' => 2, 'exchange_rate' => 1.087, 'is_default' => false, 'enabled' => false],
        ]);
    }

    public function test_settings_offers_only_enabled_currencies(): void
    {
        $this->seedCurrencies();
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now(), 'display_currency' => 'USD']);

        $response = $this->actingAs($user)->get(route('client.settings.index'));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('client/Settings/Index')
            ->where('supportedCurrencies', function ($currencies) {
                $codes = collect($currencies)->pluck('code')->sort()->values()->all();

                return $codes === ['EGP', 'SAR', 'USD'];
            })
        );
    }

    public function test_settings_accepts_an_enabled_currency(): void
    {
        $this->seedCurrencies();
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now(), 'display_currency' => 'USD']);

        $this->actingAs($user)
            ->put(route('client.settings.update'), ['display_currency' => 'EGP'])
            ->assertSessionHasNoErrors();

        $this->assertSame('EGP', $user->fresh()->display_currency);
    }

    public function test_settings_rejects_a_retired_currency(): void
    {
        $this->seedCurrencies();
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now(), 'display_currency' => 'USD']);

        $this->actingAs($user)
            ->put(route('client.settings.update'), ['display_currency' => 'EUR'])
            ->assertSessionHasErrors('display_currency');

        $this->assertSame('USD', $user->fresh()->display_currency);
    }
}
