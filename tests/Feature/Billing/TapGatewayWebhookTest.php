<?php

namespace Tests\Feature\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Billing\TapGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Locks Tap's `hashstring` field order and the JSON paths used for reference.gateway,
 * reference.payment and transaction.created. If Tap changes the order (or our code
 * drifts from the documented shape), the fixed expected hash below stops matching and
 * this test fails — which is the signal to re-confirm against a real sandbox webhook.
 */
class TapGatewayWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET_KEY = 'sk_test_tap';

    /** Precomputed HMAC-SHA256 for the fixture charge below. */
    private const EXPECTED_HASHSTRING = '8c94cb5928b18e35f087cf101131c93bd86073d5c35fa6d7fb1f008822b83bb9';

    private function gateway(): TapGateway
    {
        return new TapGateway(
            self::SECRET_KEY,
            'https://example.test/success',
            'https://example.test/cancel',
            'https://example.test/webhooks/tap',
        );
    }

    /**
     * @param  array<string, mixed>  $charge
     */
    private function request(array $charge, string $hashstring): Request
    {
        return Request::create(
            '/webhooks/tap',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_HASHSTRING' => $hashstring],
            json_encode($charge),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function charge(User $user, Plan $plan): array
    {
        return [
            'id' => 'chg_123456',
            'amount' => 10.5,
            'currency' => 'KWD',
            'status' => 'CAPTURED',
            'reference' => ['gateway' => 'gw_ref_1', 'payment' => 'pm_ref_1'],
            'transaction' => ['created' => '2026-01-01T00:00:00Z'],
            'metadata' => [
                'user_id' => (string) $user->id,
                'plan_id' => (string) $plan->id,
                'billing_cycle' => 'month',
                'kind' => 'subscription_initial',
            ],
            'card' => ['id' => 'card_1', 'payment_agreement' => ['id' => 'agr_1']],
            'customer' => ['id' => 'cus_1'],
        ];
    }

    public function test_valid_hashstring_activates_the_subscription_and_records_the_charge(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['currency_code' => 'KWD', 'monthly_price_cents' => 1050]);

        $response = $this->gateway()->handleWebhook($this->request($this->charge($user, $plan), self::EXPECTED_HASHSTRING));

        $this->assertSame(200, $response->getStatusCode());

        $subscription = Subscription::where('gateway', 'tap')->first();
        $this->assertNotNull($subscription);
        $this->assertSame('agr_1', $subscription->gateway_subscription_id);
        $this->assertSame('active', $subscription->status);
        $this->assertSame('card_1', $subscription->gateway_metadata['card_id']);
        $this->assertSame('cus_1', $subscription->gateway_metadata['customer_id']);

        $this->assertDatabaseHas('payment_transactions', [
            'gateway' => 'tap',
            'gateway_transaction_id' => 'chg_123456',
            'amount_cents' => 1050,
            'currency_code' => 'KWD',
        ]);
    }

    public function test_invalid_hashstring_is_rejected(): void
    {
        $user = User::factory()->create();
        $plan = Plan::factory()->create(['currency_code' => 'KWD', 'monthly_price_cents' => 1050]);

        $response = $this->gateway()->handleWebhook($this->request($this->charge($user, $plan), 'not-a-valid-hash'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertDatabaseMissing('subscriptions', ['gateway' => 'tap']);
        $this->assertDatabaseMissing('payment_transactions', ['gateway' => 'tap']);
    }
}
