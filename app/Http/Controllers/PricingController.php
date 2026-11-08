<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Billing\BillingGatewayRegistry;
use App\Services\CurrencyService;
use App\Services\PlanUsage;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PricingController extends Controller
{
    public function __construct(
        private CurrencyService $currency,
        private BillingGatewayRegistry $gateways
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $fallbackCurrency = $user?->display_currency
            ?? $user?->workspace?->currency_code
            ?? $request->session()->get('display_currency')
            ?? Currency::defaultCode();

        $regions = config('billing.regions', []);

        $requestedCurrency = strtoupper((string) $request->query('currency', ''));
        $displayCurrency = array_key_exists($requestedCurrency, $regions)
            ? $requestedCurrency
            : strtoupper((string) $fallbackCurrency);

        $locale = app()->getLocale();

        // A trial (or a downgrade) can leave a workspace holding more rows than a
        // smaller plan allows. Soft landing: the plan is still purchasable, but
        // the card warns which resources would stop accepting new rows.
        $workspace = null;
        if ($user instanceof User) {
            $workspaceId = $request->session()->get('current_workspace_id') ?? $user->workspace_id;
            $workspace = $workspaceId ? Workspace::with('client')->find($workspaceId) : null;

            // Never measure a workspace the user cannot access, even if a stale
            // or tampered session points at another tenant.
            if ($workspace && ! $workspace->isAccessibleBy($user)) {
                $workspace = null;
            }
        }
        $planUsage = app(PlanUsage::class);

        $plans = Plan::with('prices')
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function (Plan $plan) use ($displayCurrency, $workspace, $planUsage) {
                $monthlyQuote = $plan->priceQuote('month', $displayCurrency);
                $yearlyQuote = $plan->priceQuote('year', $displayCurrency);

                $baseMonthly = $plan->priceCentsForCycle('month');
                $baseYearly = $plan->priceCentsForCycle('year');
                $blockingLimits = $workspace ? $planUsage->breaches($workspace, $plan) : [];

                return [
                    'id' => $plan->id,
                    'name' => $plan->name,
                    'slug' => $plan->slug,
                    'description' => $plan->description,
                    'monthly_price_display' => $monthlyQuote !== null
                        ? $this->currency->format($monthlyQuote['cents'], $monthlyQuote['currency'])
                        : ($baseMonthly !== null
                            ? $this->currency->formatConverted($baseMonthly, $plan->currency_code, $displayCurrency)
                            : null),
                    'yearly_price_display' => $yearlyQuote !== null
                        ? $this->currency->format($yearlyQuote['cents'], $yearlyQuote['currency'])
                        : ($baseYearly !== null
                            ? $this->currency->formatConverted($baseYearly, $plan->currency_code, $displayCurrency)
                            : null),
                    'monthly_price_cents' => $baseMonthly,
                    'yearly_price_cents' => $baseYearly,
                    'features' => is_array($plan->features) ? $plan->features : [],
                    'limits' => is_array($plan->limits) ? $plan->limits : [],
                    'white_label_enabled' => (bool) $plan->white_label_enabled,
                    'popular' => (bool) $plan->popular,
                    'featured' => (bool) $plan->featured,
                    'is_free' => $plan->isFree(),
                    'trial_days' => $plan->trial_days,
                    // Soft landing: the workspace may already hold more than this
                    // plan allows. The plan stays selectable; the UI explains the
                    // overage and that new rows will be blocked until it fits.
                    'data_fits' => $blockingLimits === [],
                    'blocking_limits' => $blockingLimits,
                ];
            });

        $currencyOptions = collect($regions)
            ->map(fn (array $labels, string $code) => [
                'code' => $code,
                'label' => $labels[$locale] ?? $labels['en'],
            ])
            ->values()
            ->all();

        return Inertia::render('client/Pricing', [
            'plans' => $plans,
            'gateways' => $this->gateways->listForFrontend($displayCurrency),
            'is_authenticated' => (bool) $user,
            'register_url' => route('register'),
            'checkout_url' => route('client.checkout.store'),
            'currency' => $displayCurrency,
            'currency_options' => $currencyOptions,
            'flash' => [
                'error' => $request->session()->get('error'),
                'success' => $request->session()->get('success'),
            ],
        ]);
    }
}
