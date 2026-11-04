<?php

namespace App\Modules\Whatsapp\Services;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use Carbon\Carbon;
use Illuminate\Support\Carbon as IlluminateCarbon;

/**
 * Single place that decides whether a connected WhatsApp account is healthy.
 *
 * The database `status` only records the connection lifecycle
 * (active/inactive/error); this service adds the operational states the setup
 * UI needs: a token that is about to expire ("needs attention") or already
 * expired/invalid ("reauthorization required").
 */
class WhatsappConnectionHealth
{
    /** Token expires within this many days → needs attention. */
    public const EXPIRY_WARNING_DAYS = 7;

    /** A recent failure keeps the account in needs-attention for this long. */
    public const RECENT_ERROR_HOURS = 24;

    /**
     * @return array{
     *     state: string,
     *     reason: string|null,
     *     message: string|null,
     *     token_expires_at: string|null,
     *     days_until_expiry: int|null
     * }
     */
    public function for(WhatsappBusinessAccount $waba): array
    {
        $expiresAt = $waba->token_expires_at;
        $daysUntilExpiry = $expiresAt ? (int) now()->diffInDays($expiresAt, false) : null;

        $base = [
            'token_expires_at' => $expiresAt?->toIso8601String(),
            'days_until_expiry' => $daysUntilExpiry,
        ];

        if ($waba->status === 'inactive') {
            return $base + ['state' => 'disconnected', 'reason' => 'disconnected', 'message' => null];
        }

        if (! $waba->accessToken()) {
            return $base + [
                'state' => 'reauth_required',
                'reason' => 'no_token',
                'message' => 'No access token is stored for this account.',
            ];
        }

        if ($expiresAt && $expiresAt->isPast()) {
            return $base + [
                'state' => 'reauth_required',
                'reason' => 'token_expired',
                'message' => 'The WhatsApp access token expired. Reconnect the account.',
            ];
        }

        if ($waba->status === 'error') {
            if ($waba->last_error_code === 'token_invalid') {
                return $base + [
                    'state' => 'reauth_required',
                    'reason' => 'token_invalid',
                    'message' => $waba->last_error_message,
                ];
            }

            return $base + [
                'state' => 'error',
                'reason' => 'connection_error',
                'message' => $waba->last_error_message,
            ];
        }

        if ($daysUntilExpiry !== null && $daysUntilExpiry <= self::EXPIRY_WARNING_DAYS) {
            return $base + [
                'state' => 'needs_attention',
                'reason' => 'token_expiring',
                'message' => 'The WhatsApp access token expires soon. Reconnect to avoid interruption.',
            ];
        }

        if ($waba->last_error_at && $waba->last_error_at->gt(now()->subHours(self::RECENT_ERROR_HOURS))) {
            return $base + [
                'state' => 'needs_attention',
                'reason' => 'recent_error',
                'message' => $waba->last_error_message,
            ];
        }

        return $base + ['state' => 'ok', 'reason' => null, 'message' => null];
    }

    /** Normalise Meta's unix expiry (0 = never) into a Carbon instance. */
    public static function expiresAtFromMeta(int $expiresAt): ?Carbon
    {
        return $expiresAt > 0 ? IlluminateCarbon::createFromTimestamp($expiresAt) : null;
    }
}
