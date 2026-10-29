<?php

namespace App\Http\Middleware;

use App\Modules\Broadcasting\Models\UsageMeter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to enforce plan-based feature limits.
 *
 * Usage in routes:
 *   Route::post('/...')->middleware('limit:campaigns_per_month,campaigns');
 *
 * Parameters:
 *   $limitKey  – key in Plan.limits JSON (e.g. 'campaigns_per_month')
 *   $countKey  – usage_meters meter_key (e.g. 'campaigns')
 *
 * The `users` key is special-cased: it counts real team seats (users plus
 * pending invitations) instead of a monthly usage meter.
 *
 * The middleware checks whether the workspace has reached its plan limit for the
 * given meter. If so, it aborts with HTTP 402 or redirects back with an error.
 */
class EnforceLimit
{
    public function handle(Request $request, Closure $next, string $limitKey, string $countKey = ''): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $plan = $user->effectivePlan();
        $limit = $plan?->limitValue($limitKey);

        // null = unlimited (or the plan does not define this limit at all)
        if ($limit === null) {
            return $next($request);
        }

        $limit = (int) $limit;

        if ($limitKey === 'users') {
            if (! $user->client) {
                return $next($request);
            }

            $usage = $user->client->seatUsage();
        } else {
            $workspaceId = $request->session()->get('current_workspace_id') ?? $user->workspace_id;

            if (! $workspaceId) {
                return $next($request);
            }

            $meterKey = $countKey ?: $limitKey;
            $usage = UsageMeter::current((int) $workspaceId, $meterKey);
        }

        if ($usage >= $limit) {
            $limitLabel = $limitKey === 'users' ? 'team seats' : str_replace('_', ' ', $limitKey);
            $message = "You've reached your {$limitLabel} limit ({$usage}/{$limit}).";

            if ($request->expectsJson()) {
                return response()->json([
                    'error' => $message,
                    'upgrade_required' => true,
                    'limit' => $limit,
                    'current' => $usage,
                    'key' => $limitKey,
                ], 402);
            }

            return redirect('/billing')->with('upgrade_required', true)
                ->with('upgrade_reason', $message);
        }

        return $next($request);
    }
}
