<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    private function clientUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'client',
            'email_verified_at' => now(),
        ], $attrs));
    }

    public function test_authenticated_user_can_view_2fa_settings(): void
    {
        $user = $this->clientUser();
        $this->actingAs($user)
            ->get(route('client.profile.2fa'))
            ->assertOk();
    }

    public function test_2fa_enable_redirects_back_with_invalid_code(): void
    {
        $user = $this->clientUser();
        $response = $this->actingAs($user)
            ->post(route('client.profile.2fa.enable'), ['code' => '000000']);

        // Should redirect back with validation errors (invalid TOTP)
        $response->assertRedirect();
    }

    public function test_2fa_disable_requires_password(): void
    {
        $user = $this->clientUser([
            'two_factor_secret' => encrypt('TESTSECRET'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
        ]);

        // No password submitted — expect validation error
        $this->actingAs($user)
            ->post(route('client.profile.2fa.disable'))
            ->assertSessionHasErrors(['password']);
    }

    public function test_guest_cannot_access_2fa_settings(): void
    {
        $this->get(route('client.profile.2fa'))
            ->assertRedirect(route('login'));
    }

    public function test_2fa_disable_clears_secrets_with_correct_password(): void
    {
        $user = $this->clientUser([
            'two_factor_secret' => encrypt('TESTSECRET'),
            'two_factor_recovery_codes' => encrypt(json_encode(['code1', 'code2'])),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)
            ->post(route('client.profile.2fa.disable'), ['password' => 'password'])
            ->assertRedirect(route('client.profile.2fa'));

        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
    }
}
