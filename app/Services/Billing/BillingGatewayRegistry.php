<?php

namespace App\Services\Billing;

use App\Contracts\BillingGatewayInterface;
use App\Models\PaymentGatewayConfig;

class BillingGatewayRegistry
{
    /** @var array<string, BillingGatewayInterface> */
    private array $gateways = [];

    public function __construct()
    {
        $this->registerFromDatabase();
        $this->registerFromConfig();
    }

    private function registerFromDatabase(): void
    {
        try {
            $configs = PaymentGatewayConfig::where('enabled', true)->get();
        } catch (\Throwable) {
            return;
        }

        $appUrl = config('app.url', '');
        $successUrl = rtrim($appUrl, '/').'/app/billing?checkout=success';
        $cancelUrl = rtrim($appUrl, '/').'/app/pricing?checkout=canceled';
        $tapWebhookUrl = rtrim($appUrl, '/').'/webhooks/tap';

        foreach ($configs as $row) {
            if (isset($this->gateways[$row->gateway])) {
                continue;
            }
            $creds = $row->getActiveCredentials();
            if ($row->gateway === 'stripe' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['stripe'] = new StripeGateway(
                    $creds['secret_key'],
                    $creds['webhook_secret'] ?? '',
                    $successUrl,
                    $cancelUrl
                );
            }
            if ($row->gateway === 'paypal' && ! empty($creds['client_id'] ?? $creds['secret_key'] ?? '')) {
                $clientId = $creds['client_id'] ?? $creds['publishable_key'] ?? '';
                $clientSecret = $creds['client_secret'] ?? $creds['secret_key'] ?? '';
                if ($clientId !== '' && $clientSecret !== '') {
                    $this->gateways['paypal'] = new PayPalGateway(
                        $clientId,
                        $clientSecret,
                        $row->test_mode,
                        $successUrl,
                        $cancelUrl,
                        $creds['webhook_secret'] ?? $creds['webhook_id'] ?? ''
                    );
                }
            }
            // Paymob: secret_key → api_key, webhook_secret → hmac_secret,
            //         publishable_key → integration_id (numeric), extra → iframe_id.
            if ($row->gateway === 'paymob' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['paymob'] = new PaymobGateway(
                    $creds['secret_key'],
                    $creds['webhook_secret'] ?? '',
                    (int) ($creds['publishable_key'] ?? $creds['integration_id'] ?? 0),
                    $creds['iframe_id'] ?? '',
                    $successUrl,
                    $cancelUrl
                );
            }
            // MyFatoorah: secret_key → api_key.
            if ($row->gateway === 'myfatoorah' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['myfatoorah'] = new MyFatoorahGateway(
                    $creds['secret_key'],
                    (bool) $row->test_mode,
                    $successUrl,
                    $cancelUrl
                );
            }
            // Tap: secret_key → secret API key (also the webhook signing key).
            if ($row->gateway === 'tap' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['tap'] = new TapGateway(
                    $creds['secret_key'],
                    $successUrl,
                    $cancelUrl,
                    $tapWebhookUrl
                );
            }
            // Fawry: publishable_key → merchant code, secret_key → secure key.
            if ($row->gateway === 'fawry' && ! empty($creds['secret_key'] ?? '')) {
                $this->gateways['fawry'] = new FawryGateway(
                    $creds['publishable_key'] ?? $creds['merchant_code'] ?? '',
                    $creds['secret_key'],
                    (bool) $row->test_mode,
                    $successUrl,
                    $cancelUrl
                );
            }
        }
    }

    private function registerFromConfig(): void
    {
        $config = config('billing.gateways', []);

        if (! isset($this->gateways['stripe']) && ! empty($config['stripe']['enabled']) && ($config['stripe']['secret_key'] ?? '')) {
            $this->gateways['stripe'] = new StripeGateway(
                $config['stripe']['secret_key'] ?? '',
                $config['stripe']['webhook_secret'] ?? '',
                $config['stripe']['success_url'] ?? (rtrim(config('app.url'), '/').'/app/billing?checkout=success'),
                $config['stripe']['cancel_url'] ?? (rtrim(config('app.url'), '/').'/app/pricing?checkout=canceled')
            );
        }

        if (! isset($this->gateways['paypal']) && ! empty($config['paypal']['enabled']) && ($config['paypal']['client_id'] ?? '')) {
            $this->gateways['paypal'] = new PayPalGateway(
                $config['paypal']['client_id'] ?? '',
                $config['paypal']['client_secret'] ?? '',
                (bool) ($config['paypal']['sandbox'] ?? true),
                $config['paypal']['success_url'] ?? '',
                $config['paypal']['cancel_url'] ?? '',
                $config['paypal']['webhook_id'] ?? ''
            );
        }

        $appUrl = config('app.url', '');
        $successUrl = rtrim($appUrl, '/').'/app/billing?checkout=success';
        $cancelUrl = rtrim($appUrl, '/').'/app/pricing?checkout=canceled';

        if (! isset($this->gateways['paymob']) && ! empty($config['paymob']['enabled']) && ($config['paymob']['api_key'] ?? '')) {
            $this->gateways['paymob'] = new PaymobGateway(
                $config['paymob']['api_key'] ?? '',
                $config['paymob']['hmac_secret'] ?? '',
                (int) ($config['paymob']['integration_id'] ?? 0),
                $config['paymob']['iframe_id'] ?? '',
                $config['paymob']['success_url'] ?? $successUrl,
                $config['paymob']['cancel_url'] ?? $cancelUrl
            );
        }

        if (! isset($this->gateways['myfatoorah']) && ! empty($config['myfatoorah']['enabled']) && ($config['myfatoorah']['api_key'] ?? '')) {
            $this->gateways['myfatoorah'] = new MyFatoorahGateway(
                $config['myfatoorah']['api_key'] ?? '',
                (bool) ($config['myfatoorah']['sandbox'] ?? true),
                $config['myfatoorah']['success_url'] ?? $successUrl,
                $config['myfatoorah']['cancel_url'] ?? $cancelUrl
            );
        }

        if (! isset($this->gateways['tap']) && ! empty($config['tap']['enabled']) && ($config['tap']['api_key'] ?? '')) {
            $this->gateways['tap'] = new TapGateway(
                $config['tap']['api_key'] ?? '',
                $config['tap']['success_url'] ?? $successUrl,
                $config['tap']['cancel_url'] ?? $cancelUrl,
                $config['tap']['webhook_url'] ?? (rtrim($appUrl, '/').'/webhooks/tap')
            );
        }

        if (! isset($this->gateways['fawry']) && ! empty($config['fawry']['enabled']) && ($config['fawry']['secure_key'] ?? '')) {
            $this->gateways['fawry'] = new FawryGateway(
                $config['fawry']['merchant_code'] ?? '',
                $config['fawry']['secure_key'] ?? '',
                (bool) ($config['fawry']['sandbox'] ?? true),
                $config['fawry']['success_url'] ?? $successUrl,
                $config['fawry']['cancel_url'] ?? $cancelUrl
            );
        }
    }

    /**
     * @return array<string, BillingGatewayInterface>
     */
    public function all(): array
    {
        return $this->gateways;
    }

    /**
     * Only gateways that are configured (credentials present).
     *
     * @return array<string, BillingGatewayInterface>
     */
    public function configured(): array
    {
        return array_filter($this->gateways, fn (BillingGatewayInterface $g) => $g->isConfigured());
    }

    public function get(string $key): ?BillingGatewayInterface
    {
        return $this->gateways[$key] ?? null;
    }

    /**
     * @return array<array{key: string, name: string, configured: bool}>
     */
    public function listForFrontend(): array
    {
        $out = [];
        $labels = [
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
            'paymob' => 'Paymob',
            'myfatoorah' => 'MyFatoorah',
            'fawry' => 'Fawry',
            'tap' => 'Tap',
        ];
        foreach ($labels as $key => $label) {
            $g = $this->gateways[$key] ?? null;
            $out[] = [
                'key' => $key,
                'name' => $g ? $g->name() : $label,
                'configured' => $g ? $g->isConfigured() : false,
            ];
        }

        return $out;
    }
}
