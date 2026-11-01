<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces the read-only viewer role.
 *
 * Viewers may browse every page they have access to, but any state-changing
 * request is rejected unless it only touches their own session/preferences.
 * Keeping an allowlist (rather than blocking "known dangerous" routes) means a
 * new write endpoint is protected by default.
 */
class RestrictViewerWrites
{
    /**
     * Route patterns a viewer may still use for self-service.
     *
     * @var list<string>
     */
    private const ALLOWED_ROUTES = [
        'client.notifications.*',
        'client.push.*',
        'client.profile.*',
        'client.profile.sessions.*',
        'client.settings.index',
        'client.settings.update',
        'client.settings.notifications',
        'client.workspaces.switch',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isClientViewer()) {
            return $next($request);
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        foreach (self::ALLOWED_ROUTES as $pattern) {
            if ($request->routeIs($pattern)) {
                return $next($request);
            }
        }

        abort(403, __('Your account is read-only. Ask an administrator to change your role to make changes.'));
    }
}
