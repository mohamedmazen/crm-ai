<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\User;

/**
 * Reads the feature gates on the plan that is actually in effect for a user.
 *
 * Gates are stored on plans.feature_flags. A missing plan or a missing flag is
 * treated as "not included", which keeps a paid feature locked after an
 * upgrade-path mistake rather than silently giving it away.
 */
class FeatureGate
{
    private function __construct(private readonly ?Plan $plan = null) {}

    public static function forUser(?User $user): self
    {
        return new self($user?->effectivePlan());
    }

    public static function forPlan(?Plan $plan): self
    {
        return new self($plan);
    }

    public function plan(): ?Plan
    {
        return $this->plan;
    }

    public function enabled(string $feature): bool
    {
        return (bool) $this->plan?->hasFeature($feature);
    }

    /**
     * The cap attached to a feature, or null when the feature is unlimited.
     */
    public function limit(string $feature): ?int
    {
        return $this->plan?->featureLimit($feature);
    }

    /**
     * Whether the feature is enabled and still under its cap.
     */
    public function withinLimit(string $feature, int $usage): bool
    {
        if (! $this->enabled($feature)) {
            return false;
        }

        $limit = $this->limit($feature);

        return $limit === null || $usage < $limit;
    }

    /**
     * Every known gate resolved to a boolean, for Inertia props and nav filters.
     *
     * @return array<string, bool>
     */
    public function all(): array
    {
        $features = [];

        foreach (array_keys(config('features.labels', [])) as $feature) {
            $features[$feature] = $this->enabled($feature);
        }

        return $features;
    }

    public function label(string $feature): string
    {
        return (string) config("features.labels.{$feature}", $feature);
    }
}
