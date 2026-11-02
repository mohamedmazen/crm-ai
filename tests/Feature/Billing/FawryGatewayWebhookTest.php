<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\User;
use App\Services\Billing\FawryGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FawryGatewayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const MERCHANT_CODE = 'MERCHANT';

    private const SECURE_KEY = 'SECRET';

    private function gateway(): FawryGateway
    {
        return new FawryGateway(
            self::MERCHANT_CODE,
            self::SECURE_KEY,
            true,
            'https://example.test/success',
            'https://example.test/cancel',
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function signedRequest(array $data): Request
    {
        $toHash = ($data['fawryRefNumber'] ?? '')
            .($data['merchantRefNumber'] ?? '')
            .number_format((float) $data['paymentAmount'], 2, '.', '')
            .$data['orderStatus']
            .self::SECURE_KEY;
        $data['messageSignature'] = hash('sha256', $toHash);

        return Request::create(
            '/webhooks/fawry',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode($data),
        );
    }

    public function test_signed_paid_webhook_stores_amount_in_cents_for_three_decimal_currency(): void
    {
        // Regression: KWD is a 3-decimal currency. The app stores minor units as ×100,
        // so 10.500 KWD must persist as 1050 cents — not 10500 (a 10× over-store).
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['currency_code' => 'KWD', 'monthly_price_cents' => 1050]);
        $merchantRef = 'sub_'.$user->id.'_'.$plan->id.'_M_abc';

        Http::fake([
            'atfawry.fawrystaging.com/*' => Http::response([
                'orderStatus' => 'PAID',
                'paymentAmount' => 10.5,
                'currencyCode' => 'KWD',
                'fawryRefNumber' => 'FW1',
            ], 200),
        ]);

        $response = $this->gateway()->handleWebhook($this->signedRequest([
            'merchantRefNumber' => $merchantRef,
            'fawryRefNumber' => 'FW1',
            'orderStatus' => 'PAID',
            'paymentAmount' => 10.5,
            'currencyCode' => 'KWD',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('payment_transactions', [
            'gateway' => 'fawry',
            'gateway_transaction_id' => 'FW1',
            'amount_cents' => 1050,
            'currency_code' => 'KWD',
        ]);
        $this->assertDatabaseHas('subscriptions', [
            'gateway' => 'fawry',
            'gateway_subscription_id' => $merchantRef,
            'status' => 'active',
        ]);
    }

    public function test_signed_paid_webhook_is_ignored_when_status_api_disagrees(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['currency_code' => 'EGP', 'monthly_price_cents' => 25000]);
        $merchantRef = 'sub_'.$user->id.'_'.$plan->id.'_M_def';

        // A valid signature claims PAID, but the authoritative status says otherwise.
        Http::fake([
            'atfawry.fawrystaging.com/*' => Http::response(['orderStatus' => 'PENDING'], 200),
        ]);

        $response = $this->gateway()->handleWebhook($this->signedRequest([
            'merchantRefNumber' => $merchantRef,
            'fawryRefNumber' => 'FW2',
            'orderStatus' => 'PAID',
            'paymentAmount' => 250.0,
            'currencyCode' => 'EGP',
        ]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseMissing('payment_transactions', ['gateway' => 'fawry']);
        $this->assertDatabaseMissing('subscriptions', ['gateway' => 'fawry']);
    }

    public function test_unsigned_webhook_falls_back_to_authoritative_status(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['currency_code' => 'EGP', 'monthly_price_cents' => 25000]);
        $merchantRef = 'sub_'.$user->id.'_'.$plan->id.'_Y_ghi';

        Http::fake([
            'atfawry.fawrystaging.com/*' => Http::response([
                'orderStatus' => 'PAID',
                'paymentAmount' => 250.0,
                'currencyCode' => 'EGP',
                'fawryRefNumber' => 'FW3',
            ], 200),
        ]);

        $request = Request::create(
            '/webhooks/fawry',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode([
                'merchantRefNumber' => $merchantRef,
                'orderStatus' => 'PAID',
                'paymentAmount' => 1.0,
            ]),
        );

        $response = $this->gateway()->handleWebhook($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertDatabaseHas('payment_transactions', [
            'gateway' => 'fawry',
            'gateway_transaction_id' => 'FW3',
            'amount_cents' => 25000,
        ]);
    }
}
