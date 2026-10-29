<?php

namespace App\Http\Middleware;

use App\Services\FeatureGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks routes whose feature gate is not included in the current plan.
 *
 * Usage in routes:
 *   Route::middleware('feature:tasks')->group(function () { ... });
 *
 * Browser requests are redirected to the pricing page with the same
 * `upgrade_required` flash the limit middleware uses, so the existing upgrade
 * modal explains why. JSON clients get HTTP 402.
 */
class RequireFeature
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $gate = FeatureGate::forUser($request->user());

        if ($gate->enabled($feature)) {
            return $next($request);
        }

        $message = __('Your plan does not include :feature.', [
            'feature' => $gate->label($feature),
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'error' => $message,
                'upgrade_required' => true,
                'feature' => $feature,
            ], 402);
        }

        return redirect()->route('client.pricing')->with([
            'upgrade_required' => true,
            'upgrade_reason' => $message,
        ]);
    }
}
