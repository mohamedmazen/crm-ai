<?php

namespace Tests\Feature\Auth;

use App\Models\Subscription;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    public function test_new_users_can_register(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'agree_terms' => true,
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('client.dashboard', absolute: false));
    }

    public function test_new_users_receive_a_60_day_full_feature_trial(): void
    {
        $this->seed(PlanSeeder::class);

        $this->post('/register', [
            'name' => 'Trial User',
            'email' => 'trial@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'agree_terms' => true,
        ]);

        $user = User::where('email', 'trial@example.com')->firstOrFail();

        $subscription = $user->subscriptions()->where('status', 'trialing')->first();

        $this->assertNotNull($subscription, 'Registration should start a trialing subscription.');
        $this->assertSame('trial', $subscription->plan->slug);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertEqualsWithDelta(60, now()->diffInDays($subscription->trial_ends_at), 1);

        $plan = $user->client->effectivePlan();

        $this->assertNotNull($plan);
        $this->assertSame('trial', $plan->slug);
        // 100% of features are unlocked, with no numeric feature ceilings.
        $this->assertTrue($plan->hasFeature('ai_sdr'));
        $this->assertTrue($plan->hasFeature('white_label'));
        $this->assertTrue($plan->hasFeature('sales_reports'));
        $this->assertNull($plan->limitValue('pipelines'));
        $this->assertNull($plan->limitValue('automations'));

        // Data is bounded by Business limits so a trial signup cannot grow the
        // platform's storage or row counts without limit.
        $this->assertSame(30, $plan->limitValue('users'));
        $this->assertSame(250000, $plan->limitValue('max_contacts'));
        $this->assertSame(256000, $plan->limitValue('storage'));
        $this->assertSame(10000000, $plan->limitValue('ai_tokens_per_month'));
    }

    public function test_registration_succeeds_when_no_trial_plan_is_seeded(): void
    {
        // Fresh installs without PlanSeeder must still register users; the
        // trial simply does not start rather than breaking the request.
        $response = $this->post('/register', [
            'name' => 'No Trial User',
            'email' => 'notrial@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'agree_terms' => true,
        ]);

        $response->assertRedirect(route('client.dashboard', absolute: false));
        $this->assertSame(0, Subscription::count());
    }
}
