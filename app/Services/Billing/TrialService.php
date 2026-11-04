<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;

/**
 * Starts the platform-wide free trial that every new self-serve signup gets.
 *
 * The trial is modelled as an ordinary `trialing` Subscription on a hidden
 * "trial" plan whose feature flags are fully unlocked. That reuses the whole
 * existing billing pipeline: `Client::effectivePlan()` picks the trial up,
 * `billing:expire-trials` cancels it when `trial_ends_at` passes, and the
 * reminder command (`notifications:trial-ending`) keeps working unchanged.
 *
 * Buying a paid plan later simply creates a newer active/trialing Subscription,
 * which `effectivePlan()` prefers over the trial — no special-casing needed.
 */
class TrialService
{
    /** Slug of the hidden, fully-unlocked plan defined in PlanSeeder. */
    public const TRIAL_PLAN_SLUG = 'trial';

    /** Fallback length when the plan row is missing a trial_days value. */
    public const DEFAULT_TRIAL_DAYS = 60;

    /**
     * Attach the free trial to a user, unless the trial plan is unconfigured or
     * the user already has a subscription granting access (never downgrade an
     * existing customer who registers a second account against the same user).
     */
    public function startFor(User $user): ?Subscription
    {
        $plan = Plan::where('slug', self::TRIAL_PLAN_SLUG)->first();

        if (! $plan) {
            return null;
        }

        if ($user->subscriptions()->whereIn('status', ['active', 'trialing'])->exists()) {
            return null;
        }

        $trialDays = $plan->trial_days > 0 ? $plan->trial_days : self::DEFAULT_TRIAL_DAYS;
        $startsAt = now();

        return $user->subscriptions()->create([
            'plan_id' => $plan->id,
            'status' => 'trialing',
            'billing_cycle' => 'month',
            'starts_at' => $startsAt,
            'gateway' => 'manual',
            'gateway_subscription_id' => null,
            'trial_ends_at' => $startsAt->copy()->addDays($trialDays),
        ]);
    }
}
