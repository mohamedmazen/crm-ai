<?php

namespace App\Support\Http;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches user-supplied URLs with SSRF protections.
 *
 * Knowledge-base documents accept arbitrary URLs (and sitemaps contain more),
 * so a tenant could otherwise make the server request internal services or the
 * cloud metadata endpoint and leak the response through the chatbot.
 *
 * Every hop is validated independently: only http/https, no embedded
 * credentials, no private/reserved IPs, and redirects are followed manually so
 * the new destination is checked before it is requested.
 */
final class SafeUrlFetcher
{
    private const MAX_REDIRECTS = 5;

    /** Hostnames that never resolve to something we should fetch. */
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'metadata',
        'metadata.google.internal',
        'instance-data',
    ];

    /** IPv4 ranges PHP's NO_RES_RANGE filter does not cover. */
    private const BLOCKED_IPV4_CIDRS = [
        '100.64.0.0/10',    // Carrier-grade NAT
        '192.0.0.0/24',     // IETF protocol assignments
        '198.18.0.0/15',    // Benchmarking
        '198.51.100.0/24',  // Documentation
        '203.0.113.0/24',   // Documentation
    ];

    /**
     * Fetch a URL and return its body, or null for a non-2xx response.
     *
     * @throws RuntimeException when the URL is unsafe or redirects too often.
     */
    public function get(string $url, int $timeout = 30): ?string
    {
        $result = $this->fetch($url, $timeout);

        return $result['not_modified'] ? null : $result['body'];
    }

    /**
     * Fetch a URL with optional conditional headers.
     *
     * Returns the status, body and cache validators so callers can re-index
     * only when the remote document actually changed (If-None-Match /
     * If-Modified-Since). A 304 is reported as `not_modified` rather than
     * treated as a redirect.
     *
     * @param  array<string, string>  $headers
     * @return array{status: int, body: ?string, etag: ?string, last_modified: ?string, not_modified: bool}
     *
     * @throws RuntimeException when the URL is unsafe or redirects too often.
     */
    public function fetch(string $url, int $timeout = 30, array $headers = []): array
    {
        $current = $url;
        $redirects = 0;
        // Conditional headers only describe the original resource; replaying
        // them on a redirect target would make the remote compare validators
        // for a different resource.
        $conditional = $headers;

        while (true) {
            $this->assertSafe($current);

            $response = Http::timeout($timeout)
                ->withHeaders($conditional)
                ->withOptions(['allow_redirects' => false])
                ->get($current);

            if ($response->status() === 304) {
                return [
                    'status' => 304,
                    'body' => null,
                    'etag' => $this->headerOrNull($response->header('ETag')),
                    'last_modified' => $this->headerOrNull($response->header('Last-Modified')),
                    'not_modified' => true,
                ];
            }

            if ($response->redirect()) {
                if (++$redirects > self::MAX_REDIRECTS) {
                    throw new RuntimeException('Too many redirects while fetching the URL.');
                }

                $location = (string) $response->header('Location');
                if ($location === '') {
                    throw new RuntimeException('Redirect response is missing a Location header.');
                }

                $current = $this->resolveLocation($current, $location);
                $conditional = [];

                continue;
            }

            return [
                'status' => $response->status(),
                'body' => $response->successful() ? $response->body() : null,
                'etag' => $this->headerOrNull($response->header('ETag')),
                'last_modified' => $this->headerOrNull($response->header('Last-Modified')),
                'not_modified' => false,
            ];
        }
    }

    private function headerOrNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Validate a URL (and every IP its host resolves to) before requesting it.
     *
     * @throws RuntimeException
     */
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);

        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('The URL is invalid.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Only http and https URLs can be fetched.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('URLs with embedded credentials are not allowed.');
        }

        $host = strtolower(trim($parts['host'], '[]'));

        if ($host === '' || $this->isBlockedHostname($host)) {
            throw new RuntimeException('That host is not allowed.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($host);

            return;
        }

        // Fail closed only when DNS gives us something to check. An unresolvable
        // host will fail at request time anyway.
        foreach ($this->resolveHost($host) as $ip) {
            $this->assertPublicIp($ip);
        }
    }

    private function isBlockedHostname(string $host): bool
    {
        return in_array($host, self::BLOCKED_HOSTNAMES, true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
    }

    /**
     * @throws RuntimeException
     */
    private function assertPublicIp(string $ip): void
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('The URL host did not resolve to a valid address.');
        }

        // Rejects private ranges (10/8, 172.16/12, 192.168/16, fc00::/7) and
        // reserved ranges (127/8, 169.254/16, ::1, fe80::/10, ...).
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            throw new RuntimeException('The URL points to a private or reserved address.');
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            foreach (self::BLOCKED_IPV4_CIDRS as $cidr) {
                if ($this->ipInCidr($ip, $cidr)) {
                    throw new RuntimeException('The URL points to a private or reserved address.');
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function resolveHost(string $host): array
    {
        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function resolveLocation(string $base, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $location;
        }

        return (string) UriResolver::resolve(new Uri($base), new Uri($location));
    }

    private function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $bits = (int) $bits;

        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);

        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === 0 ? 0 : (-1 << (32 - $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }
}
