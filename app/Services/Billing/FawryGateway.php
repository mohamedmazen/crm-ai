<?php

namespace App\Services\Billing;

use App\Contracts\BillingGatewayInterface;
use App\Events\SubscriptionStarted;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\WebhookIdempotencyService;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fawry (Egypt) one-time billing.
 *
 * Fawry has no merchant-initiated recurring product, so this gateway only sells a single
 * billing cycle at a time. The customer pays once (card, mobile wallet, or cash at any
 * Fawry outlet via a generated reference number) and the local subscription grants access
 * until the end of the purchased cycle (ends_at/renews_at). Renewal is a fresh checkout.
 *
 * Flow:
 *   1. createCheckout() calls FawryPay Express Checkout (`/fawrypay-api/api/payments/init`),
 *      which returns a hosted redirect URL where the customer picks the payment method.
 *   2. Fawry redirects the browser back to returnUrl (carrying our merchantRefNumber) and
 *      posts a server notification to the configured dashboard webhook.
 *   3. Both paths are fulfilled through fulfillCheckoutSession()/handleWebhook(), which
 *      confirm the authoritative status via Get Payment Status V2 before activating.
 *
 * Amounts are in MAJOR currency units (e.g. 250.00 EGP, not piastres). The app stores
 * `amount_cents` (×100); convert to major units for API calls.
 */
class FawryGateway implements BillingGatewayInterface
{
    private const PROD_URL = 'https://www.atfawry.com';

    private const TEST_URL = 'https://atfawry.fawrystaging.com';

    /** Currencies that use 3 decimal places (ISO 4217 minor unit = 1000). */
    private const THREE_DECIMAL_CURRENCIES = ['KWD', 'BHD', 'OMR', 'JOD', 'TND', 'LYD'];

    /** Order statuses that mean the payment will never complete. */
    private const DEAD_STATUSES = ['EXPIRED', 'CANCELED', 'CANCELLED', 'FAILED'];

    private string $baseUrl;

    public function __construct(
        private string $merchantCode,
        private string $secureKey,
        private bool $sandbox,
        private string $successUrl,
        private string $cancelUrl,
    ) {
        $this->baseUrl = $sandbox ? self::TEST_URL : self::PROD_URL;
    }

    public function name(): string
    {
        return 'Fawry';
    }

    public function isConfigured(): bool
    {
        return $this->merchantCode !== '' && $this->secureKey !== '';
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()->asJson();
    }

    public function createCheckout(User $user, Plan $plan, string $billingCycle): array
    {
        if (! $this->isConfigured()) {
            return ['error' => 'Fawry is not configured.'];
        }

        $priceCents = $plan->priceCentsForCycle($billingCycle);
        if ($priceCents === null || $priceCents <= 0) {
            return ['error' => 'Plan has no price for this billing cycle.'];
        }

        $amount = number_format($priceCents / 100, 2, '.', '');

        // Encode user/plan/cycle in the merchant reference so the webhook can recover them.
        $merchantRefNum = $this->buildMerchantRefNum($user, $plan, $billingCycle);
        $returnUrl = $this->appendQuery($this->successUrl, ['merchantRefNumber' => $merchantRefNum]);

        $items = [[
            'itemId' => 'plan_'.$plan->id.'_'.$billingCycle,
            'description' => $plan->name.' ('.$billingCycle.')',
            'price' => $amount,
            'quantity' => 1,
        ]];

        $signature = hash('sha256',
            $this->merchantCode
            .$merchantRefNum
            .$user->id
            .$returnUrl
            .$this->itemsSignatureString($items)
            .$this->secureKey
        );

        $body = [
            'merchantCode' => $this->merchantCode,
            'merchantRefNum' => $merchantRefNum,
            'customerName' => $user->name ?: ('User '.$user->id),
            'customerEmail' => $user->email ?? '',
            'customerProfileId' => (string) $user->id,
            'paymentExpiry' => now()->addDays(2)->getTimestampMs(),
            'language' => 'en-gb',
            'chargeItems' => $items,
            'returnUrl' => $returnUrl,
            'authCaptureModePayment' => false,
            'signature' => $signature,
        ];

        $res = $this->http()->post($this->baseUrl.'/fawrypay-api/api/payments/init', $body);

        if (! $res->successful()) {
            Log::error('Fawry checkout init failed', ['body' => $res->json() ?? $res->body(), 'user_id' => $user->id]);

            return ['error' => data_get($res->json(), 'statusDescription', 'Fawry payment creation failed.')];
        }

        // The Express Checkout endpoint responds with the redirect URL as plain text.
        $url = trim($res->body());
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $json = $res->json();
            Log::error('Fawry checkout init returned no URL', ['response' => $json ?? $url, 'user_id' => $user->id]);

            return ['error' => data_get($json, 'statusDescription', 'Fawry did not return a payment URL.')];
        }

        return ['url' => $url, 'subscription_id' => $merchantRefNum];
    }

    /**
     * Called when the customer returns to the success URL. $sessionId is the merchant
     * reference number we appended to returnUrl.
     */
    public function fulfillCheckoutSession(string $sessionId): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Fawry is not configured.'];
        }

        $lookup = $this->fetchStatus($sessionId);
        if ($lookup === null) {
            return ['ok' => false, 'error' => 'Could not verify Fawry payment status.'];
        }

        if ($lookup['status'] !== 'PAID') {
            return ['ok' => false, 'error' => 'Payment not yet completed (status: '.$lookup['status'].').'];
        }

        $subscription = $this->activateFromStatus($sessionId, $lookup['data']);

        if (! $subscription) {
            return ['ok' => false, 'error' => 'Missing user/plan metadata in Fawry status response.'];
        }

        return ['ok' => true, 'error' => null, 'subscription' => $subscription];
    }

    public function handleWebhook(Request $request): Response
    {
        $data = json_decode($request->getContent(), true) ?: [];
        $merchantRef = (string) ($data['merchantRefNumber'] ?? $data['merchantRefNum'] ?? '');
        $reportedStatus = strtoupper((string) ($data['orderStatus'] ?? ''));

        if ($merchantRef === '') {
            return new Response('OK', 200);
        }

        // Fawry signs the notification; if that cannot be verified (or no signature was sent)
        // fall back to the authoritative status API so a spoofed POST cannot activate access.
        if ($this->verifyWebhookSignature($data)) {
            $status = $reportedStatus;
        } else {
            $lookup = $this->fetchStatus($merchantRef);
            if ($lookup === null) {
                Log::warning('Fawry webhook rejected: unverified notification and status lookup failed', [
                    'merchant_ref' => $merchantRef,
                ]);

                return new Response('Invalid signature', 401);
            }
            $status = $lookup['status'];
            $data = array_merge($data, $lookup['data']);
        }

        $eventKey = $merchantRef.'_'.$status;
        if (! app(WebhookIdempotencyService::class)->isNewEvent('fawry', $eventKey)) {
            return new Response('OK', 200);
        }

        try {
            if ($status === 'PAID') {
                $this->activateFromStatus($merchantRef, $data);
            } elseif (in_array($status, self::DEAD_STATUSES, true)) {
                Subscription::where('gateway', 'fawry')
                    ->where('gateway_subscription_id', $merchantRef)
                    ->update(['status' => 'past_due']);
            }
        } catch (\Throwable $e) {
            Log::error('Fawry webhook handler failed', ['merchant_ref' => $merchantRef, 'error' => $e->getMessage()]);
            app(WebhookIdempotencyService::class)->release('fawry', $eventKey);

            return new Response('Handler error', 500);
        }

        return new Response('OK', 200);
    }

    /** Verify Fawry's notification signature: sha256(fawryRef + merchantRef + paymentAmount + orderStatus + secureKey). */
    private function verifyWebhookSignature(array $data): bool
    {
        $received = (string) ($data['messageSignature'] ?? $data['signature'] ?? '');
        if ($this->secureKey === '' || $received === '') {
            return false;
        }

        $toHash = (string) ($data['fawryRefNumber'] ?? $data['referenceNumber'] ?? '')
            .(string) ($data['merchantRefNumber'] ?? $data['merchantRefNum'] ?? '')
            .$this->formatAmount((float) ($data['paymentAmount'] ?? 0))
            .(string) ($data['orderStatus'] ?? '')
            .$this->secureKey;

        $expected = hash('sha256', $toHash);

        if (! app()->environment('production')) {
            Log::debug('Fawry webhook signature check', ['expected' => $expected, 'received' => $received, 'to_hash' => $toHash]);
        }

        return hash_equals($expected, $received);
    }

    /**
     * Look up the authoritative order status from Fawry (Get Payment Status V2).
     *
     * @return array{status: string, data: array}|null
     */
    private function fetchStatus(string $merchantRefNum): ?array
    {
        if (! $this->isConfigured() || $merchantRefNum === '') {
            return null;
        }

        $signature = hash('sha256', $this->merchantCode.$merchantRefNum.$this->secureKey);

        try {
            $res = $this->http()->get($this->baseUrl.'/ECommerceWeb/Fawry/payments/status/v2', [
                'merchantCode' => $this->merchantCode,
                'merchantRefNumber' => $merchantRefNum,
                'signature' => $signature,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Fawry status lookup failed', ['merchant_ref' => $merchantRefNum, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $res->successful()) {
            Log::warning('Fawry status lookup error', ['merchant_ref' => $merchantRefNum, 'body' => $res->json()]);

            return null;
        }

        $data = $res->json() ?: [];

        return [
            'status' => strtoupper((string) ($data['orderStatus'] ?? '')),
            'data' => $data,
        ];
    }

    /** Create or refresh the local subscription for a confirmed-paid Fawry order. */
    private function activateFromStatus(string $merchantRef, array $data): ?Subscription
    {
        $meta = $this->parseMerchantRefNum($merchantRef);
        if (! $meta) {
            Log::warning('Fawry: could not parse merchant reference', ['merchant_ref' => $merchantRef]);

            return null;
        }

        $plan = Plan::find($meta['plan_id']);
        if (! $plan) {
            return null;
        }

        $currency = strtoupper((string) ($data['currencyCode'] ?? $plan->currency_code ?? 'EGP'));
        $paid = (float) ($data['paymentAmount'] ?? $data['orderAmount'] ?? 0);
        $amountCents = $paid > 0
            ? $this->minorAmount($paid, $currency)
            : ($plan->priceCentsForCycle($meta['billing_cycle']) ?? 0);

        $renewal = $this->nextRenewal($meta['billing_cycle']);
        $fawryRef = (string) ($data['fawryRefNumber'] ?? $data['referenceNumber'] ?? '');

        $isNew = ! Subscription::where('gateway', 'fawry')
            ->where('gateway_subscription_id', $merchantRef)
            ->exists();

        $subscription = Subscription::updateOrCreate(
            ['gateway' => 'fawry', 'gateway_subscription_id' => $merchantRef],
            [
                'user_id' => $meta['user_id'],
                'plan_id' => $plan->id,
                'billing_cycle' => $meta['billing_cycle'],
                'status' => 'active',
                'starts_at' => now(),
                // One-time purchase: access lasts for the bought cycle; renewal is a new checkout.
                'ends_at' => $renewal,
                'renews_at' => $renewal,
                'gateway_metadata' => [
                    'merchant_ref' => $merchantRef,
                    'fawry_ref' => $fawryRef,
                    'payment_method' => $data['paymentMethod'] ?? null,
                    'currency' => $currency,
                ],
            ]
        );

        $transactionId = $fawryRef !== '' ? $fawryRef : $merchantRef;
        if ($amountCents > 0 && ! PaymentTransaction::where('gateway', 'fawry')->where('gateway_transaction_id', $transactionId)->exists()) {
            $transaction = PaymentTransaction::create([
                'user_id' => $meta['user_id'],
                'subscription_id' => $subscription->id,
                'gateway' => 'fawry',
                'gateway_transaction_id' => $transactionId,
                'amount_cents' => $amountCents,
                'currency_code' => $currency,
                'status' => 'paid',
                'payload' => $data,
            ]);
            $this->generateInvoicePdf($transaction);
        }

        if ($isNew) {
            $user = User::find($meta['user_id']);
            if ($user) {
                SubscriptionStarted::dispatch($user, $subscription, $plan);
            }
        }

        return $subscription;
    }

    public function cancel(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'fawry') {
            return false;
        }

        // Fawry charges are one-time; there is no remote recurring mandate to cancel.
        $subscription->update(['status' => 'canceled', 'ends_at' => now()]);

        return true;
    }

    public function sync(Subscription $subscription): bool
    {
        if ($subscription->gateway !== 'fawry' || ! $this->isConfigured()) {
            return false;
        }

        $merchantRef = (string) ($subscription->gateway_subscription_id
            ?? ($subscription->gateway_metadata['merchant_ref'] ?? ''));

        $lookup = $this->fetchStatus($merchantRef);
        if ($lookup === null) {
            return false;
        }

        $mapped = match ($lookup['status']) {
            'PAID' => 'active',
            'NEW', 'PENDING' => 'incomplete',
            'EXPIRED', 'CANCELED', 'CANCELLED', 'FAILED' => 'canceled',
            default => $subscription->status,
        };

        $subscription->update(['status' => $mapped]);

        return true;
    }

    public function changePlan(Subscription $subscription, Plan $newPlan, string $billingCycle): array
    {
        // One-time billing: the new plan price is charged on the customer's next checkout.
        $subscription->update(['plan_id' => $newPlan->id, 'billing_cycle' => $billingCycle]);

        return ['ok' => true, 'error' => null];
    }

    public function refund(PaymentTransaction $transaction, ?int $amountCents = null): array
    {
        if (! $this->isConfigured()) {
            return ['ok' => false, 'error' => 'Fawry is not configured.'];
        }

        $referenceNumber = $transaction->gateway_transaction_id;
        if (! $referenceNumber) {
            return ['ok' => false, 'error' => 'No gateway transaction ID for refund.'];
        }

        $currency = strtoupper($transaction->currency_code ?? 'EGP');
        $cents = $amountCents ?? $transaction->amount_cents;
        $refundAmount = $this->majorAmountString($cents, $currency);
        $reason = 'requested_by_customer';

        $signature = hash('sha256',
            $this->merchantCode.$referenceNumber.$refundAmount.$reason.$this->secureKey
        );

        $res = $this->http()->post($this->baseUrl.'/ECommerceWeb/Fawry/payments/refund', [
            'merchantCode' => $this->merchantCode,
            'referenceNumber' => $referenceNumber,
            'refundAmount' => $refundAmount,
            'reason' => $reason,
            'signature' => $signature,
        ]);

        if (! $res->successful()) {
            Log::error('Fawry refund failed', ['transaction_id' => $transaction->id, 'response' => $res->json()]);

            return ['ok' => false, 'error' => data_get($res->json(), 'statusDescription', 'Fawry refund failed.')];
        }

        $transaction->update([
            'refunded_at' => now(),
            'refunded_cents' => $cents,
            'status' => 'refunded',
        ]);

        return ['ok' => true, 'error' => null];
    }

    /** Encode user/plan/cycle in an alphanumeric merchant reference the webhook can parse. */
    private function buildMerchantRefNum(User $user, Plan $plan, string $billingCycle): string
    {
        $cycle = $billingCycle === 'year' ? 'Y' : 'M';
        $suffix = base_convert((string) now()->getTimestampMs(), 10, 36);

        return 'sub_'.$user->id.'_'.$plan->id.'_'.$cycle.'_'.$suffix;
    }

    /**
     * @return array{user_id: int, plan_id: int, billing_cycle: string}|null
     */
    private function parseMerchantRefNum(string $merchantRef): ?array
    {
        if (! preg_match('/^sub_(\d+)_(\d+)_([MY])_/', $merchantRef, $matches)) {
            return null;
        }

        return [
            'user_id' => (int) $matches[1],
            'plan_id' => (int) $matches[2],
            'billing_cycle' => $matches[3] === 'Y' ? 'year' : 'month',
        ];
    }

    /** Concatenate item fields exactly as Fawry expects them in the checkout signature. */
    private function itemsSignatureString(array $items): string
    {
        return implode('', array_map(
            fn (array $item): string => $item['itemId'].$item['quantity'].number_format((float) $item['price'], 2, '.', ''),
            $items
        ));
    }

    private function appendQuery(string $url, array $params): string
    {
        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($params);
    }

    private function nextRenewal(string $billingCycle): Carbon
    {
        return $billingCycle === 'year' ? now()->addYear() : now()->addMonth();
    }

    private function currencyDecimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::THREE_DECIMAL_CURRENCIES, true) ? 3 : 2;
    }

    /** Format a major-unit amount to the currency's fixed precision (for signatures). */
    private function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /** Convert the app's amount_cents (×100) to Fawry's major-unit string. */
    private function majorAmountString(int $cents, string $currency): string
    {
        return number_format($cents / 100, $this->currencyDecimals($currency), '.', '');
    }

    /** Convert a Fawry major-unit amount back to the app's amount_cents (×100). */
    private function minorAmount(float $amount, string $currency): int
    {
        return (int) round($amount * (10 ** $this->currencyDecimals($currency)));
    }

    /** Best-effort PDF invoice generation; never let it break webhook processing. */
    private function generateInvoicePdf(PaymentTransaction $transaction): void
    {
        try {
            app(InvoiceService::class)->generate($transaction);
        } catch (\Throwable $e) {
            Log::warning('Fawry: invoice PDF generation failed', ['transaction_id' => $transaction->id, 'error' => $e->getMessage()]);
        }
    }
}
