<?php

namespace Tests\Feature\Security;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Firebase ID token is the only proof of identity on this endpoint, so the
 * audience and issuer checks must be exact. A loose match previously let an ID
 * token minted for any other Firebase project (or any Google app, via the
 * `https://accounts.google.com` issuer) sign in as an arbitrary user.
 */
class FirebaseTokenVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const PROJECT_ID = 'crm-project-123';

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::set('firebase_enabled', 'true');
        SystemSetting::set('firebase_project_id', self::PROJECT_ID);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function tokenInfo(array $overrides = []): array
    {
        return array_merge([
            'aud' => self::PROJECT_ID,
            'iss' => 'https://securetoken.google.com/'.self::PROJECT_ID,
            'sub' => 'firebase-uid-1',
            'email' => 'firebase-user@example.com',
            'email_verified' => 'true',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $tokenInfo
     */
    private function attempt(array $tokenInfo): TestResponse
    {
        Http::fake([
            'www.googleapis.com/*' => Http::response($tokenInfo, 200),
        ]);

        return $this->postJson(route('auth.firebase'), ['id_token' => 'fake-id-token']);
    }

    #[Test]
    public function it_accepts_a_token_minted_for_this_firebase_project(): void
    {
        $this->attempt($this->tokenInfo())
            ->assertOk()
            ->assertJsonStructure(['redirect']);

        $this->assertAuthenticated();
    }

    #[Test]
    public function it_rejects_a_token_minted_for_another_firebase_project(): void
    {
        $this->attempt($this->tokenInfo(['aud' => 'some-other-project']))
            ->assertStatus(422);

        $this->assertGuest();
    }

    #[Test]
    public function it_rejects_an_audience_that_only_contains_the_project_id(): void
    {
        // Regression guard for the old Str::contains($aud, $projectId) check.
        $this->attempt($this->tokenInfo(['aud' => self::PROJECT_ID.'-attacker']))
            ->assertStatus(422);

        $this->assertGuest();
    }

    #[Test]
    public function it_rejects_the_generic_google_issuer(): void
    {
        $this->attempt($this->tokenInfo(['iss' => 'https://accounts.google.com']))
            ->assertStatus(422);

        $this->assertGuest();
    }

    #[Test]
    public function it_rejects_a_token_without_a_subject(): void
    {
        $this->attempt($this->tokenInfo(['sub' => '']))
            ->assertStatus(422);

        $this->assertGuest();
    }

    #[Test]
    public function it_rejects_a_token_with_an_issuer_for_another_project(): void
    {
        $this->attempt($this->tokenInfo(['iss' => 'https://securetoken.google.com/another-project']))
            ->assertStatus(422);

        $this->assertGuest();
    }
}
