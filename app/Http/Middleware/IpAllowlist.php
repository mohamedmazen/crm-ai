<?php

namespace App\Http\Middleware;

use App\Models\SystemSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional IP / CIDR allowlist for the super-admin panel.
 *
 * Disabled by default. When `security.ip_allowlist_enabled` is "1" and the list
 * is non-empty, only matching client IPs may reach the admin panel. An empty
 * list while enabled is treated as "not configured" so a half-saved setting can
 * never lock every administrator out.
 */
class IpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->enabled()) {
            return $next($request);
        }

        $entries = $this->entries();

        if ($entries === []) {
            return $next($request);
        }

        $ip = $request->ip();

        if ($ip === null || ! $this->isAllowed($ip, $entries)) {
            abort(403, __('Your IP address is not allowed to access this area.'));
        }

        return $next($request);
    }

    private function enabled(): bool
    {
        return SystemSetting::get('security.ip_allowlist_enabled', '0') === '1';
    }

    /**
     * @return list<string>
     */
    private function entries(): array
    {
        $raw = (string) SystemSetting::get('security.ip_allowlist', '');

        return array_values(array_filter(
            array_map('trim', preg_split('/[\s,]+/', $raw) ?: []),
            fn (string $entry): bool => $entry !== '',
        ));
    }

    /**
     * @param  list<string>  $entries
     */
    private function isAllowed(string $ip, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (str_contains($entry, '/')) {
                if ($this->matchesCidr($ip, $entry)) {
                    return true;
                }

                continue;
            }

            if ($this->normalize($ip) === $this->normalize($entry)) {
                return true;
            }
        }

        return false;
    }

    private function matchesCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        // IPv6 CIDR is not supported; fall back to an exact match.
        if ($ipLong === false || $subnetLong === false) {
            return $this->normalize($ip) === $this->normalize($subnet);
        }

        $bits = $bits === null ? 32 : (int) $bits;

        if ($bits < 0 || $bits > 32) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private function normalize(string $ip): string
    {
        $packed = @inet_pton($ip);

        return $packed === false ? $ip : $packed;
    }
}
