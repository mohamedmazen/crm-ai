<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class CheckoutController extends Controller
{
    public function __construct(
        private BillingGatewayRegistry $gateways
    ) {}

    /**
     * Start checkout for a plan with the selected gateway and billing cycle.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan_id' => ['required', 'integer', Rule::exists('plans', 'id')],
            'billing_cycle' => ['required', 'string', Rule::in(['month', 'year'])],
            'gateway' => ['required', 'string', Rule::in(['stripe', 'paypal', 'paymob', 'myfatoorah', 'fawry', 'tap'])],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $plan = Plan::with('prices')->where('enabled', true)->findOrFail($validated['plan_id']);
        $gateway = $this->gateways->get($validated['gateway']);

        if (! $gateway || ! $gateway->isConfigured()) {
            return back()->with('error', __('That payment gateway is not configured.'));
        }

        $currency = isset($validated['currency']) ? strtoupper($validated['currency']) : null;
        $quote = $plan->priceQuote($validated['billing_cycle'], $currency);

        if ($quote === null || $quote['cents'] <= 0) {
            return back()->with('error', __('That currency is not available for this plan.'));
        }

        if (! BillingGatewayRegistry::supportsCurrency($validated['gateway'], $quote['currency'])) {
            return back()->with('error', __('That payment gateway does not support this currency.'));
        }

        $result = $gateway->createCheckout($request->user(), $plan, $validated['billing_cycle'], $quote['currency']);

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        // Most gateways return a hosted redirect URL.
        if (isset($result['url'])) {
            return Inertia::location($result['url']);
        }

        return back()->with('error', __('Checkout could not be started.'));
    }
}
