<?php

namespace App\Rules;

use App\Http\Middleware\ResolveTenantDomain;
use App\Models\Client;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A workspace subdomain: a DNS label that is not reserved by the platform and
 * is unique across workspaces. The optional client is excluded from the
 * uniqueness check so a workspace can keep its own subdomain on update.
 */
class WorkspaceSubdomain implements ValidationRule
{
    public function __construct(private ?Client $ignore = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $subdomain = strtolower(trim($value));

        if (! ResolveTenantDomain::isValidSubdomain($subdomain)) {
            $fail(__('Use 3–63 lowercase letters, digits or hyphens, starting and ending with a letter or digit.'));

            return;
        }

        if (in_array($subdomain, ResolveTenantDomain::reservedSubdomains(), true)) {
            $fail(__('That subdomain is reserved by the platform.'));

            return;
        }

        $taken = Client::query()
            ->where('subdomain', $subdomain)
            ->when($this->ignore, fn ($query) => $query->whereKeyNot($this->ignore->getKey()))
            ->exists();

        if ($taken) {
            $fail(__('That subdomain is already in use.'));
        }
    }
}
