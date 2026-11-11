<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ClientAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the client role permissions.
 *
 * The check is deliberately route-driven rather than controller-driven: every
 * client route already has a name, and `ClientAccess` maps that name to a
 * `module.action` permission. A route no module claims is denied, so a newly
 * added endpoint is protected by default instead of silently open.
 *
 * State-changing methods need `module.write` (or `module.view` for read-only
 * modules such as the audit log); GET requests need `module.view`.
 */
class EnsureClientPermission
{
    public function __construct(private readonly ClientAccess $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->client_id) {
            return $next($request);
        }

        $routeName = $request->route()?->getName();

        if (! $routeName) {
            return $next($request);
        }

        $required = $this->access->requiredPermissionFor($routeName, $request->method());

        // Self-service route (dashboard, own profile, notifications, ...).
        if ($required === null) {
            return $next($request);
        }

        if ($required !== ClientAccess::UNMAPPED && $this->access->userCan($user, $required)) {
            return $next($request);
        }

        abort(403, __('Your role does not allow this action.'));
    }
}
