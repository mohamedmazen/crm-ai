<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The 60-day free trial is an ordinary `trialing` Subscription on the hidden
 * "trial" plan, so the existing billing:expire-trials command owns its
 * lifecycle. These tests pin that contract.
 */
class TrialLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function trialingSubscription(int $trialEndsInDays): Subscription
    {
        $plan = Plan::factory()->create(['slug' => 'trial', 'trial_days' => 60]);
        $user = User::factory()->create();

        return Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'billing_cycle' => 'month',
            'starts_at' => now()->subDay(),
            'gateway' => 'manual',
            'gateway_subscription_id' => null,
            'trial_ends_at' => now()->addDays($trialEndsInDays),
        ]);
    }

    public function test_expire_trials_cancels_a_finished_trial(): void
    {
        Event::fake();

        $subscription = $this->trialingSubscription(-1);

        $this->artisan('billing:expire-trials')->assertSuccessful();

        $this->assertSame('canceled', $subscription->fresh()->status);
    }

    public function test_expire_trials_leaves_a_running_trial_alone(): void
    {
        Event::fake();

        $subscription = $this->trialingSubscription(30);

        $this->artisan('billing:expire-trials')->assertSuccessful();

        $this->assertSame('trialing', $subscription->fresh()->status);
    }
}
