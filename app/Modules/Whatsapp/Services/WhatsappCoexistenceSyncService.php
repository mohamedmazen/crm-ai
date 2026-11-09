<?php

namespace App\Modules\Whatsapp\Services;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Support\MetaGraph;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Requests the one-shot WhatsApp Business app data sync from Meta.
 *
 * Meta only accepts `smb_app_data` once per business, inside the 24 hours
 * after onboarding. The customer must explicitly consent first; this service
 * records per-sync flags in `meta_json` so a queued retry never re-sends a
 * request Meta has already accepted.
 */
class WhatsappCoexistenceSyncService
{
    public const STATUS_NONE = 'none';

    public const STATUS_PENDING_CONSENT = 'pending_consent';

    public const STATUS_CONSENTED = 'consented';

    public const STATUS_REQUESTED = 'requested';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const SYNC_CONTACTS = 'smb_app_state_sync';

    public const SYNC_HISTORY = 'history';

    /**
     * When the customer's consent window closes (onboarding time + 24h).
     */
    public function windowEndsAt(WhatsappBusinessAccount $waba): ?Carbon
    {
        $linkedAt = $waba->coexistence_linked_at ?? $waba->created_at;

        if ($linkedAt === null) {
            return null;
        }

        return $linkedAt->copy()->addHours((int) config('whatsapp.coexistence.history_sync_window_hours', 24));
    }

    public function windowIsOpen(WhatsappBusinessAccount $waba): bool
    {
        if (! $waba->is_coexistence) {
            return false;
        }

        $endsAt = $this->windowEndsAt($waba);

        return $endsAt !== null && now()->lt($endsAt);
    }

    /**
     * Ask Meta for the address book and then the chat history.
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public function requestAll(WhatsappBusinessAccount $waba, string $accessToken): array
    {
        $errors = [];

        foreach ([self::SYNC_CONTACTS, self::SYNC_HISTORY] as $syncType) {
            if ($this->alreadyRequested($waba, $syncType)) {
                continue;
            }

            $result = $this->request($waba, $accessToken, $syncType);

            if (! $result['ok']) {
                $errors[] = $result['error'] ?? 'Unknown Meta error';

                continue;
            }

            $this->markRequested($waba, $syncType, $result['request_id'] ?? null);
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * @return array{ok: bool, error: string|null, request_id: string|null, status: int}
     */
    public function request(WhatsappBusinessAccount $waba, string $accessToken, string $syncType): array
    {
        $phoneNumberId = $this->businessPhoneNumberId($waba);

        if ($phoneNumberId === null) {
            return [
                'ok' => false,
                'error' => 'No coexistence phone number is attached to this account.',
                'request_id' => null,
                'status' => 0,
            ];
        }

        $response = Http::withToken($accessToken)
            ->timeout(30)
            ->post(MetaGraph::url($phoneNumberId.'/smb_app_data'), [
                'messaging_product' => 'whatsapp',
                'sync_type' => $syncType,
            ]);

        if ($response->successful()) {
            $requestId = $response->json('request_id');

            Log::info('whatsapp.coexistence.sync_requested', [
                'workspace_id' => $waba->workspace_id,
                'waba_id' => $waba->waba_id,
                'phone_number_id' => $phoneNumberId,
                'sync_type' => $syncType,
                'request_id' => $requestId,
            ]);

            return [
                'ok' => true,
                'error' => null,
                'request_id' => is_string($requestId) ? $requestId : null,
                'status' => $response->status(),
            ];
        }

        $error = $this->metaError($response);

        Log::warning('whatsapp.coexistence.sync_request_failed', [
            'workspace_id' => $waba->workspace_id,
            'waba_id' => $waba->waba_id,
            'phone_number_id' => $phoneNumberId,
            'sync_type' => $syncType,
            'status' => $response->status(),
            'error' => $error,
        ]);

        return [
            'ok' => false,
            'error' => $error,
            'request_id' => null,
            'status' => $response->status(),
        ];
    }

    /**
     * The coexistence number is captured during Embedded Signup; fall back to
     * the workspace's active channel for accounts connected before this flag
     * existed.
     */
    public function businessPhoneNumberId(WhatsappBusinessAccount $waba): ?string
    {
        $stored = $waba->meta_json['coexistence_phone_number_id'] ?? null;

        if (is_string($stored) && $stored !== '') {
            return $stored;
        }

        $fallback = WhatsappBusinessAccount::defaultPhoneNumberIdForWorkspace((int) $waba->workspace_id);

        return $fallback !== null && $fallback !== '' ? (string) $fallback : null;
    }

    public function alreadyRequested(WhatsappBusinessAccount $waba, string $syncType): bool
    {
        $key = $syncType === self::SYNC_CONTACTS
            ? 'coexistence_contacts_sync_requested'
            : 'coexistence_history_sync_requested';

        return (bool) ($waba->meta_json[$key] ?? false);
    }

    private function markRequested(WhatsappBusinessAccount $waba, string $syncType, ?string $requestId): void
    {
        $key = $syncType === self::SYNC_CONTACTS
            ? 'coexistence_contacts_sync_requested'
            : 'coexistence_history_sync_requested';

        $meta = $waba->meta_json ?? [];
        $meta[$key] = true;

        if ($requestId !== null) {
            $meta[$key.'_id'] = $requestId;
        }

        $waba->update([
            'meta_json' => $meta,
            'coexistence_last_sync_at' => now(),
        ]);
    }

    private function metaError(Response $response): string
    {
        $message = $response->json('error.message');

        if (is_string($message) && $message !== '') {
            return $message;
        }

        return 'Meta returned HTTP '.$response->status();
    }
}
