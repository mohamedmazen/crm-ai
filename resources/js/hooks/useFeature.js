import { usePage } from '@inertiajs/react';

/**
 * Whether the plan in effect includes a feature gate.
 *
 * The flags are shared from the server (FeatureGate::all()) so the frontend
 * never has to know how plans are stored.
 */
export default function useFeature(feature) {
    const { plan_features } = usePage().props;

    return Boolean(plan_features?.[feature]);
}
