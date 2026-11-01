<?php

namespace App\Modules\Broadcasting\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Checks whether a sending domain publishes SPF and DKIM records.
 *
 * Mail sent from a domain without these records is far more likely to be
 * filtered as spam, so the email-server page surfaces the result before a
 * workspace starts sending sequences or campaigns. Lookups are cached briefly
 * to keep a page refresh from hammering DNS.
 */
class DomainVerifier
{
    /** Common DKIM selectors used by hosted mail providers. */
    public const COMMON_SELECTORS = [
        'default',
        'mail',
        'dkim',
        'google',
        'selector1',
        'selector2',
        's1',
        's2',
        'k1',
    ];

    public function __construct(private readonly int $ttl = 3600) {}

    /**
     * Resolve a domain from an email address ("user@example.com" → "example.com").
     */
    public static function domainFromEmail(string $email): string
    {
        $domain = str_contains($email, '@') ? substr(strrchr($email, '@') ?: '', 1) : $email;

        return strtolower(trim($domain));
    }

    /**
     * @return array{domain: string, spf: bool, dkim: bool, dkim_selector: string|null, verified: bool}
     */
    public function verify(string $domain): array
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return [
                'domain' => '',
                'spf' => false,
                'dkim' => false,
                'dkim_selector' => null,
                'verified' => false,
            ];
        }

        return Cache::remember(
            "domain-verification:{$domain}",
            $this->ttl,
            fn (): array => $this->check($domain),
        );
    }

    /**
     * @return array{domain: string, spf: bool, dkim: bool, dkim_selector: string|null, verified: bool}
     */
    public function check(string $domain): array
    {
        $spf = $this->hasSpfRecord($domain);
        $selector = $this->findDkimSelector($domain);

        return [
            'domain' => $domain,
            'spf' => $spf,
            'dkim' => $selector !== null,
            'dkim_selector' => $selector,
            'verified' => $spf && $selector !== null,
        ];
    }

    public function forget(string $domain): void
    {
        Cache::forget('domain-verification:'.strtolower(trim($domain)));
    }

    private function hasSpfRecord(string $domain): bool
    {
        foreach ($this->txtRecords($domain) as $record) {
            if (str_starts_with(strtolower($record), 'v=spf1')) {
                return true;
            }
        }

        return false;
    }

    private function findDkimSelector(string $domain): ?string
    {
        foreach (self::COMMON_SELECTORS as $selector) {
            foreach ($this->txtRecords("{$selector}._domainkey.{$domain}") as $record) {
                $record = strtolower($record);

                if (str_contains($record, 'v=dkim1') || str_contains($record, 'p=')) {
                    return $selector;
                }
            }
        }

        return null;
    }

    /**
     * Fetch TXT records for a name. Kept small and overridable so the verifier
     * can be tested without live DNS.
     *
     * @return list<string>
     */
    protected function txtRecords(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (! is_array($records)) {
            Log::debug('DomainVerifier: DNS lookup failed.', ['name' => $name]);

            return [];
        }

        $values = [];

        foreach ($records as $record) {
            $txt = $record['txt'] ?? null;

            if (is_string($txt) && $txt !== '') {
                $values[] = $txt;
            }

            // Some resolvers return long TXT records chunked into entries.
            if (isset($record['entries']) && is_array($record['entries'])) {
                $values[] = implode('', array_map('strval', $record['entries']));
            }
        }

        return $values;
    }
}
