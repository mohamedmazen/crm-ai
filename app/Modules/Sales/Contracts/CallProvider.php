<?php

namespace App\Modules\Sales\Contracts;

use App\Modules\Sales\Services\Calls\CallProviderManager;
use App\Modules\Sales\Services\Calls\CallUpdate;
use Illuminate\Http\Request;

/**
 * A telephony provider that can place an outbound call and report its lifecycle.
 *
 * Implementations are resolved per workspace by
 * {@see CallProviderManager}; the app never
 * talks to a vendor SDK directly, so adding a provider is one class + one match arm.
 */
interface CallProvider
{
    /** Provider slug stored on the call log (`twilio`, `telnyx`, …). */
    public function name(): string;

    /**
     * Place an outbound call and return the provider's call id.
     *
     * @param  array<string, mixed>  $options
     *
     * @throws \RuntimeException when the provider rejects the request
     */
    public function initiate(string $to, string $from, string $callbackUrl, array $options = []): string;

    /** Whether an inbound webhook request genuinely came from this provider. */
    public function verifyWebhook(Request $request): bool;

    /** Normalise a webhook payload, or null when the event is not a call update. */
    public function parseWebhook(Request $request): ?CallUpdate;
}
