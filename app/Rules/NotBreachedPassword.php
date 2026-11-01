<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rejects passwords that appear in the Have I Been Pwned breach corpus.
 *
 * The range API is queried with k-anonymity: we send the first five characters
 * of the password's SHA-1 hash and compare the returned suffixes locally, so the
 * full password (or its hash) never leaves the server. The check fails open when
 * the service is unreachable — availability beats a perfect breach check.
 */
class NotBreachedPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return;
        }

        $count = $this->breachCount($value);

        if ($count === null || $count === 0) {
            return;
        }

        $fail(__('This password has appeared in a data breach :count times. Choose a different password.', [
            'count' => number_format($count),
        ]));
    }

    /**
     * Number of times the password appears in the breach corpus, 0 when clean,
     * or null when the check could not be performed.
     */
    public function breachCount(string $password): ?int
    {
        if (! config('security.breach_check', true)) {
            return null;
        }

        $hash = strtoupper(sha1($password));
        $prefix = substr($hash, 0, 5);
        $suffix = substr($hash, 5);

        try {
            $response = Http::timeout(3)
                ->retry(1, 100)
                ->get("https://api.pwnedpasswords.com/range/{$prefix}");
        } catch (Throwable $e) {
            Log::warning('Password breach check skipped: HIBP lookup failed.', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        foreach (preg_split('/\r?\n/', trim($response->body())) as $line) {
            [$candidate, $count] = array_pad(explode(':', $line, 2), 2, '0');

            if (strtoupper(trim($candidate)) === $suffix) {
                return (int) $count;
            }
        }

        return 0;
    }
}
