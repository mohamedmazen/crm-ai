<?php

namespace App\Http\Middleware;

use App\Models\Client;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the workspace that owns the current Host header.
 *
 * A request to `<subdomain>.<base_domain>` or to a workspace's custom domain is
 * bound to that workspace for the duration of the request. Requests on a
 * central host (marketing site, admin panel, login) are left untouched. A
 * signed-in user may only use the workspace they belong to, so a cookie from
 * one tenant never opens another tenant's data.
 */
class ResolveTenantDomain
{
    public const ATTRIBUTE = 'tenant';

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('tenancy.enabled', true)) {
            return $next($request);
        }

        $host = self::normalizeDomain($request->getHost());
        $base = self::normalizeDomain((string) config('tenancy.base_domain', ''));

        $tenant = $this->resolveCustomDomain($host) ?? $this->resolveSubdomain($host, $base);

        if (! $tenant) {
            if ($base === '' || $this->isCentralHost($host, $base)) {
                return $next($request);
            }

            abort(404, __('Workspace not found.'));
        }

        $request->attributes->set(self::ATTRIBUTE, $tenant);

        $user = $request->user();

        if ($user instanceof User && $user->client_id && (int) $user->client_id !== (int) $tenant->id) {
            abort(403, __('Your account does not belong to this workspace.'));
        }

        return $next($request);
    }

    /**
     * The workspace bound to the current request, if any.
     */
    public static function tenant(Request $request): ?Client
    {
        $tenant = $request->attributes->get(self::ATTRIBUTE);

        return $tenant instanceof Client ? $tenant : null;
    }

    /**
     * Lower-case a host, strip the scheme, port, path and a trailing dot.
     */
    public static function normalizeDomain(string $domain): string
    {
        $domain = trim($domain);

        if (str_contains($domain, '://')) {
            $domain = (string) parse_url($domain, PHP_URL_HOST);
        }

        $domain = strtolower(trim(explode('/', $domain)[0]));

        if (str_contains($domain, ':')) {
            $domain = explode(':', $domain)[0];
        }

        return rtrim($domain, '.');
    }

    /**
     * Subdomains are DNS labels: lowercase letters, digits and inner hyphens.
     */
    public static function isValidSubdomain(string $subdomain): bool
    {
        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{1,61}[a-z0-9])?$/', $subdomain);
    }

    /**
     * @return list<string>
     */
    public static function reservedSubdomains(): array
    {
        return array_map('strtolower', (array) config('tenancy.reserved_subdomains', []));
    }

    private function resolveCustomDomain(string $host): ?Client
    {
        if ($host === '') {
            return null;
        }

        return Client::query()
            ->where('status', Client::STATUS_ACTIVE)
            ->whereRaw('LOWER(custom_domain) = ?', [$host])
            ->first();
    }

    private function resolveSubdomain(string $host, string $base): ?Client
    {
        if ($base === '' || ! str_ends_with($host, '.'.$base)) {
            return null;
        }

        $prefix = substr($host, 0, -(strlen($base) + 1));

        if ($prefix === '' || str_contains($prefix, '.') || ! self::isValidSubdomain($prefix)) {
            return null;
        }

        if (in_array($prefix, self::reservedSubdomains(), true)) {
            return null;
        }

        return Client::query()
            ->where('status', Client::STATUS_ACTIVE)
            ->where('subdomain', $prefix)
            ->first();
    }

    private function isCentralHost(string $host, string $base): bool
    {
        if ($host === '' || $host === $base || $host === 'www.'.$base) {
            return true;
        }

        $central = array_merge(
            (array) config('tenancy.central_domains', []),
            [self::normalizeDomain((string) config('app.url', ''))],
        );

        return in_array($host, array_map([self::class, 'normalizeDomain'], $central), true);
    }
}
