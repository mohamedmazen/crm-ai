<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveTenantDomain;
use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\Currency;
use App\Models\Locale;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $supportedLocales = Locale::enabled()->orderByRaw('is_default DESC')->orderBy('sort_order')->get(['code', 'name']);
        if ($supportedLocales->isEmpty()) {
            $supportedLocales = collect([['code' => 'en', 'name' => 'English']]);
        }
        $supportedCurrencies = Currency::where('enabled', true)->orderBy('code')->get(['code', 'symbol']);

        $client = null;
        if ($user->client_id && $user->isClientAdministrator()) {
            $c = $user->client;
            if ($c) {
                $client = [
                    'id' => $c->id,
                    'name' => $c->name,
                    'email' => $c->email,
                    'phone' => $c->phone,
                    'address' => $c->address,
                    'subdomain' => $c->subdomain,
                    'custom_domain' => $c->custom_domain,
                ];
            }
        }

        return Inertia::render('client/Settings/Index', [
            'preferences' => [
                'locale' => $user->locale ?? config('app.locale', 'en'),
                'display_currency' => $user->display_currency ?? 'USD',
                'theme' => $user->theme ?? 'light',
                'timezone' => $user->timezone ?? 'Asia/Dhaka',
            ],
            'supportedLocales' => $supportedLocales->map(fn ($l) => ['code' => $l->code, 'name' => $l->name]),
            'supportedCurrencies' => $supportedCurrencies->map(fn ($c) => ['code' => $c->code, 'name' => $c->code, 'symbol' => $c->symbol ?? $c->code]),
            'client' => $client,
            'tenantBaseDomain' => config('tenancy.base_domain'),
            'tenantBaseDomainEnabled' => (bool) config('tenancy.enabled', true) && filled(config('tenancy.base_domain')),
            'digestEnabled' => $user->client_id
                ? ClientSetting::get($user->client_id, 'weekly_digest_enabled', '1') !== '0'
                : true,
        ]);
    }

    public function notifications(Request $request): Response
    {
        $user = $request->user();

        $preferences = $user->notificationPreferences
            ->groupBy('event')
            ->map(fn ($group) => $group->mapWithKeys(fn ($p) => [$p->channel => (bool) $p->enabled]));

        return Inertia::render('client/Settings/Notifications', [
            'preferences' => $preferences,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $localeCodes = Locale::enabled()->pluck('code')->all() ?: ['en'];
        $currencyCodes = Currency::where('enabled', true)->pluck('code')->all() ?: ['USD'];
        $clientForDomains = $user->client_id && $user->isClientAdministrator() ? $user->client : null;

        $validated = $request->validate([
            'locale' => ['nullable', 'string', 'max:16', Rule::in($localeCodes)],
            'display_currency' => ['nullable', 'string', 'max:10', Rule::in($currencyCodes)],
            'theme' => ['nullable', 'string', 'in:light,dark'],
            'timezone' => ['nullable', 'string', 'max:64', 'timezone:all'],
            'client_name' => ['nullable', 'string', 'max:255'],
            'client_email' => ['nullable', 'email', 'max:255'],
            'client_phone' => ['nullable', 'string', 'max:64'],
            'client_address' => ['nullable', 'string'],
            'client_subdomain' => ['nullable', 'string', 'max:63', $this->subdomainRule($clientForDomains)],
            'client_custom_domain' => ['nullable', 'string', 'max:255', $this->customDomainRule($clientForDomains)],
            'weekly_digest_enabled' => ['nullable', 'boolean'],
        ]);

        if (array_key_exists('locale', $validated) && $validated['locale'] !== null) {
            $user->locale = $validated['locale'];
        }
        if (array_key_exists('display_currency', $validated) && $validated['display_currency'] !== null) {
            $user->display_currency = $validated['display_currency'];
        }
        if (array_key_exists('theme', $validated) && $validated['theme'] !== null) {
            $user->theme = $validated['theme'];
        }
        if (array_key_exists('timezone', $validated) && $validated['timezone'] !== null) {
            $user->timezone = $validated['timezone'];
        }
        $user->save();

        if ($user->client_id && $user->isClientAdministrator() && $user->client) {
            $client = $user->client;
            if (array_key_exists('client_name', $validated)) {
                $client->name = $validated['client_name'] ?? $client->name;
            }
            if (array_key_exists('client_email', $validated)) {
                $client->email = $validated['client_email'];
            }
            if (array_key_exists('client_phone', $validated)) {
                $client->phone = $validated['client_phone'];
            }
            if (array_key_exists('client_address', $validated)) {
                $client->address = $validated['client_address'];
            }
            if (array_key_exists('client_subdomain', $validated)) {
                $subdomain = strtolower(trim((string) $validated['client_subdomain']));
                $client->subdomain = $subdomain === '' ? null : $subdomain;
            }
            if (array_key_exists('client_custom_domain', $validated)) {
                $domain = ResolveTenantDomain::normalizeDomain((string) $validated['client_custom_domain']);
                $client->custom_domain = $domain === '' ? null : $domain;
            }
            $client->save();
        }

        // Digest preference
        if ($user->client_id && array_key_exists('weekly_digest_enabled', $validated)) {
            ClientSetting::set($user->client_id, 'weekly_digest_enabled', $validated['weekly_digest_enabled'] ? '1' : '0');
        }

        return redirect()->route('client.settings.index')->with('success', __('Settings saved.'));
    }

    /**
     * Validate a workspace subdomain: DNS-safe label, not reserved, unique.
     */
    private function subdomainRule(?Client $client): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($client): void {
            if (! $client || ! is_string($value) || trim($value) === '') {
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
                ->whereKeyNot($client->getKey())
                ->exists();

            if ($taken) {
                $fail(__('That subdomain is already in use.'));
            }
        };
    }

    /**
     * Validate a custom domain: bare hostname, unique across workspaces.
     */
    private function customDomainRule(?Client $client): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($client): void {
            if (! $client || ! is_string($value) || trim($value) === '') {
                return;
            }

            $domain = ResolveTenantDomain::normalizeDomain($value);

            if (! preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
                $fail(__('Enter a hostname such as crm.example.com (no scheme or path).'));

                return;
            }

            $taken = Client::query()
                ->whereRaw('LOWER(custom_domain) = ?', [$domain])
                ->whereKeyNot($client->getKey())
                ->exists();

            if ($taken) {
                $fail(__('That domain is already connected to another workspace.'));
            }
        };
    }
}
