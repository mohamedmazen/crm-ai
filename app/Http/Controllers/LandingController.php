<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Admin\LandingPageController;
use App\Models\Currency;
use App\Models\Plan;
use App\Models\SystemSetting;
use App\Services\CurrencyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;

class LandingController extends Controller
{
    public function __construct(private CurrencyService $currency) {}

    private function landingDisabledRedirect(): ?RedirectResponse
    {
        if (SystemSetting::get('landing.page_enabled', '1') === '1' || ! Route::has('login')) {
            return null;
        }

        return redirect()->route('login');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function plans(?string $currencyCode = null): array
    {
        try {
            return Plan::with('prices')
                ->where('enabled', true)
                ->orderBy('sort_order')
                ->get()
                ->map(function ($plan) use ($currencyCode) {
                    $monthly = $plan->priceQuote('month', $currencyCode);
                    $yearly = $plan->priceQuote('year', $currencyCode);

                    return [
                        'id' => $plan->id,
                        'name' => $plan->name,
                        'slug' => $plan->slug,
                        'description' => $plan->description ?? '',
                        'price_monthly' => $monthly ? $monthly['cents'] / 100 : 0,
                        'price_yearly' => $yearly ? $yearly['cents'] / 100 : 0,
                        'price_monthly_display' => $monthly ? $this->currency->format($monthly['cents'], $monthly['currency']) : null,
                        'price_yearly_display' => $yearly ? $this->currency->format($yearly['cents'], $yearly['currency']) : null,
                        'features' => is_array($plan->features) ? $plan->features : [],
                        'is_featured' => (bool) ($plan->featured ?? $plan->popular ?? false),
                        'trial_days' => $plan->trial_days ?? 0,
                    ];
                })
                ->values()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return array<int, array{code: string, label: string}>
     */
    private function currencyOptions(): array
    {
        $locale = app()->getLocale();

        return collect(config('billing.regions', []))
            ->map(fn (array $labels, string $code) => [
                'code' => $code,
                'label' => $labels[$locale] ?? $labels['en'],
            ])
            ->values()
            ->all();
    }

    private function selectedCurrency(Request $request): string
    {
        $regions = config('billing.regions', []);
        $requested = strtoupper((string) $request->query('currency', ''));

        return array_key_exists($requested, $regions)
            ? $requested
            : strtoupper((string) (Currency::defaultCode() ?? 'USD'));
    }

    public function index(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'landing' => LandingPageController::getPublicSettings(),
            'plans' => $this->plans(),
        ]);
    }

    public function pricing(Request $request): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        $currency = $this->selectedCurrency($request);

        return Inertia::render('marketing/Pricing', [
            'canRegister' => Route::has('register'),
            'landing' => LandingPageController::getPublicSettings(),
            'plans' => $this->plans($currency),
            'currency' => $currency,
            'currency_options' => $this->currencyOptions(),
        ]);
    }

    public function faq(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('marketing/Faq', [
            'canRegister' => Route::has('register'),
            'landing' => LandingPageController::getPublicSettings(),
        ]);
    }

    public function useCases(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('marketing/UseCases', [
            'canRegister' => Route::has('register'),
            'landing' => LandingPageController::getPublicSettings(),
        ]);
    }

    public function about(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('marketing/About', [
            'canRegister' => Route::has('register'),
            'landing' => LandingPageController::getPublicSettings(),
        ]);
    }

    public function integrations(): Response|RedirectResponse
    {
        if ($redirect = $this->landingDisabledRedirect()) {
            return $redirect;
        }

        return Inertia::render('marketing/Integrations', [
            'canRegister' => Route::has('register'),
            'landing' => LandingPageController::getPublicSettings(),
        ]);
    }
}
