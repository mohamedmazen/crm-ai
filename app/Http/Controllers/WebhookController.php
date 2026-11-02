<?php

namespace App\Http\Controllers;

use App\Services\Billing\BillingGatewayRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class WebhookController extends Controller
{
    // Headers that must never appear in logs
    private const SENSITIVE_HEADERS = [
        'authorization',
        'stripe-signature',
        'paypal-transmission-sig',
        'paypal-cert-url',
        'x-webhook-signature',
        'x-signature',
        'hashstring',
        'x-hub-signature',
        'x-hub-signature-256',
        'cookie',
    ];

    public function __construct(
        private BillingGatewayRegistry $gateways
    ) {}

    /**
     * Stripe webhook (no auth; verified by signature inside gateway).
     */
    public function stripe(Request $request): Response
    {
        Log::channel('single')->info('Stripe webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('stripe');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * PayPal webhook.
     */
    public function paypal(Request $request): Response
    {
        Log::channel('single')->info('PayPal webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('paypal');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * Paymob webhook.
     */
    public function paymob(Request $request): Response
    {
        Log::channel('single')->info('Paymob webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('paymob');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    /**
     * MyFatoorah webhook.
     */
    public function myfatoorah(Request $request): Response
    {
        Log::channel('single')->info('MyFatoorah webhook received', [
            'headers' => $this->safeHeaders($request),
            'body_length' => strlen($request->getContent()),
        ]);

        $gateway = $this->gateways->get('myfatoorah');
        if (! $gateway) {
            return new Response('Gateway not configured', 503);
        }

        return $gateway->handleWebhook($request);
    }

    private function safeHeaders(Request $request): array
    {
        return collect($request->headers->all())
            ->except(self::SENSITIVE_HEADERS)
            ->toArray();
    }
}
