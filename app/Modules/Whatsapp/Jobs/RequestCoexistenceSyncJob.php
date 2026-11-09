<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\WhatsappCoexistenceSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sends the customer-approved WhatsApp Business app data sync request to Meta.
 *
 * Runs on the whatsapp queue because Meta only accepts the request inside the
 * 24-hour onboarding window and the HTTP call should not block the consent
 * form. Retries are safe: the sync service records each accepted request in
 * `meta_json` and skips it on the next attempt.
 */
class RequestCoexistenceSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function __construct(private readonly int $wabaId) {}

    public function handle(WhatsappCoexistenceSyncService $sync): void
    {
        $waba = WhatsappBusinessAccount::find($this->wabaId);

        if (! $waba || ! $waba->is_coexistence) {
            return;
        }

        if (! in_array($waba->coexistence_status, [
            WhatsappBusinessAccount::COEXISTENCE_ACTIVE,
            WhatsappBusinessAccount::COEXISTENCE_SUSPENDED,
        ], true)) {
            return;
        }

        if (! in_array($waba->history_sync_status, [
            WhatsappCoexistenceSyncService::STATUS_CONSENTED,
            WhatsappCoexistenceSyncService::STATUS_REQUESTED,
            WhatsappCoexistenceSyncService::STATUS_FAILED,
        ], true)) {
            return;
        }

        if (! $sync->windowIsOpen($waba)) {
            $waba->update(['history_sync_status' => WhatsappCoexistenceSyncService::STATUS_EXPIRED]);

            Log::warning('whatsapp.coexistence.sync_window_closed', [
                'workspace_id' => $waba->workspace_id,
                'waba_id' => $waba->waba_id,
            ]);

            return;
        }

        $token = $waba->accessToken();

        if (! $token) {
            $waba->update(['history_sync_status' => WhatsappCoexistenceSyncService::STATUS_FAILED]);

            throw new \RuntimeException('No WhatsApp access token available for coexistence sync.');
        }

        $result = $sync->requestAll($waba, $token);

        if (! $result['ok']) {
            $waba->update(['history_sync_status' => WhatsappCoexistenceSyncService::STATUS_FAILED]);

            throw new \RuntimeException(
                'WhatsApp coexistence sync request failed: '.implode('; ', $result['errors'])
            );
        }

        $waba->update([
            'history_sync_status' => WhatsappCoexistenceSyncService::STATUS_REQUESTED,
            'coexistence_last_sync_at' => now(),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        WhatsappBusinessAccount::where('id', $this->wabaId)->update([
            'history_sync_status' => WhatsappCoexistenceSyncService::STATUS_FAILED,
        ]);

        Log::error('RequestCoexistenceSyncJob failed permanently', [
            'waba_id' => $this->wabaId,
            'error' => $e->getMessage(),
        ]);
    }
}
