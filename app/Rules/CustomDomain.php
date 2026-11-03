<?php

namespace App\Rules;

use App\Http\Middleware\ResolveTenantDomain;
use App\Models\Client;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A custom domain: a bare hostname (no scheme or path) that is unique across
 * workspaces. The optional client is excluded from the uniqueness check so a
 * workspace can keep its own domain on update.
 */
class CustomDomain implements ValidationRule
{
    public function __construct(private ?Client $ignore = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $domain = ResolveTenantDomain::normalizeDomain($value);

        if (! preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            $fail(__('Enter a hostname such as crm.example.com (no scheme or path).'));

            return;
        }

        $taken = Client::query()
            ->whereRaw('LOWER(custom_domain) = ?', [$domain])
            ->when($this->ignore, fn ($query) => $query->whereKeyNot($this->ignore->getKey()))
            ->exists();

        if ($taken) {
            $fail(__('That domain is already connected to another workspace.'));
        }
    }
}
