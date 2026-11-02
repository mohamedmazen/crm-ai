<?php

namespace App\Modules\Sales\Services\Calls;

use Illuminate\Http\Request;

/**
 * A per-config token appended to the provider status callback URL.
 *
 * Twilio and Telnyx sign their own callbacks, but CEQUENS and Unifonic do not,
 * and Unifonic's callback URL is registered once per voice application rather
 * than per call. The token is an HMAC of the config id + provider under the app
 * key, so it is stable, never stored, and only grants writes to that one
 * workspace's call log.
 */
final class CallWebhookToken
{
    public static function for(string $provider, int $configId): string
    {
        return hash_hmac('sha256', $provider.':'.$configId, (string) config('app.key'));
    }

    /** The signed URL a workspace registers with the provider. */
    public static function url(string $provider, int $configId): string
    {
        return route('webhooks.calls.status', [
            'provider' => $provider,
            'config' => $configId,
        ]).'?token='.self::for($provider, $configId);
    }

    /** Whether the current request carries the token for its own route config. */
    public static function matches(Request $request): bool
    {
        $provider = (string) $request->route('provider');
        $configId = (int) $request->route('config');

        if ($provider === '' || $configId === 0) {
            return false;
        }

        $given = (string) ($request->query('token') ?? $request->header('X-Webhook-Token', ''));

        return $given !== '' && hash_equals(self::for($provider, $configId), $given);
    }
}
