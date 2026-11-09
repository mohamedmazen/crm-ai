<?php

namespace App\Modules\Whatsapp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Modules\Shared\Models\ChannelAccount;
use App\Modules\Whatsapp\Jobs\RequestCoexistenceSyncJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Services\WhatsappCoexistenceContactImporter;
use App\Modules\Whatsapp\Services\WhatsappCoexistenceSyncService;
use App\Services\AuditLogService;
use App\Services\FeatureGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WhatsappCoexistenceController extends Controller
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly WhatsappCoexistenceContactImporter $importer,
        private readonly WhatsappCoexistenceSyncService $sync,
    ) {}

    /**
     * Record the customer's explicit consent, then queue the one-shot request
     * to Meta (address book + chat history).
     */
    public function consent(Request $request, WhatsappBusinessAccount $waba): RedirectResponse
    {
        $workspaceId = $this->authorizeWaba($request, $waba);

        if (! config('whatsapp.coexistence.enabled', false)) {
            return back()->with('error', 'WhatsApp Coexistence is disabled on this platform.');
        }

        if (! FeatureGate::forWorkspace(Workspace::find($workspaceId))->enabled('whatsapp_coexistence')) {
            return back()->with('error', 'WhatsApp Coexistence requires the Pro or Business plan.');
        }

        if (! $waba->is_coexistence || $waba->coexistence_status !== WhatsappBusinessAccount::COEXISTENCE_ACTIVE) {
            return back()->with('error', 'Reconnect the number in the WhatsApp Business app before importing data.');
        }

        if (in_array($waba->history_sync_status, [
            WhatsappCoexistenceSyncService::STATUS_CONSENTED,
            WhatsappCoexistenceSyncService::STATUS_REQUESTED,
            WhatsappCoexistenceSyncService::STATUS_COMPLETED,
        ], true)) {
            return back()->with('success', 'The WhatsApp Business app data import is already in progress.');
        }

        if (! $this->sync->windowIsOpen($waba)) {
            $waba->update(['history_sync_status' => WhatsappCoexistenceSyncService::STATUS_EXPIRED]);

            return back()->with('error', 'The 24-hour import window has closed. Disconnect the number in the WhatsApp Business app and connect it again to restart the window.');
        }

        $waba->update([
            'history_sync_status' => WhatsappCoexistenceSyncService::STATUS_CONSENTED,
            'history_sync_consented_at' => now(),
        ]);

        RequestCoexistenceSyncJob::dispatch($waba->id)->onQueue('whatsapp');

        $this->audit->log('whatsapp.coexistence.consent', $waba, null, [
            'workspace_id' => $workspaceId,
            'waba_id' => $waba->waba_id,
            'history_sync_status' => WhatsappCoexistenceSyncService::STATUS_CONSENTED,
        ]);

        return back()->with('success', 'Import started. Your WhatsApp Business app contacts and chat history will appear in the inbox shortly.');
    }

    /**
     * The customer declined the import. Keep the number connected; only the
     * historical data stays out of the CRM.
     */
    public function decline(Request $request, WhatsappBusinessAccount $waba): RedirectResponse
    {
        $workspaceId = $this->authorizeWaba($request, $waba);

        if (in_array($waba->history_sync_status, [
            WhatsappCoexistenceSyncService::STATUS_CONSENTED,
            WhatsappCoexistenceSyncService::STATUS_REQUESTED,
            WhatsappCoexistenceSyncService::STATUS_COMPLETED,
        ], true)) {
            return back()->with('error', 'The import has already started and cannot be cancelled.');
        }

        $waba->update(['history_sync_status' => WhatsappCoexistenceSyncService::STATUS_DECLINED]);

        $this->audit->log('whatsapp.coexistence.declined', $waba, null, [
            'workspace_id' => $workspaceId,
            'waba_id' => $waba->waba_id,
        ]);

        return back()->with('success', 'Import skipped. New messages from the WhatsApp Business app will still appear in the inbox.');
    }

    /**
     * Try to move staged contacts into the CRM after an upgrade or after the
     * customer frees up contact slots.
     */
    public function retryPending(Request $request, WhatsappBusinessAccount $waba): RedirectResponse
    {
        $workspaceId = $this->authorizeWaba($request, $waba);

        $workspace = Workspace::find($workspaceId);

        if (! $workspace) {
            return back()->with('error', 'Workspace not found.');
        }

        $result = $this->importer->retryPending($workspace);

        $this->audit->log('whatsapp.coexistence.pending_contacts_retried', $waba, null, [
            'workspace_id' => $workspaceId,
            'waba_id' => $waba->waba_id,
            'imported' => $result['imported'],
            'remaining' => $result['remaining'],
        ]);

        if ($result['imported'] === 0) {
            return back()->with('error', 'No contacts could be imported yet — the plan contact limit is still full.');
        }

        return back()->with('success', "Imported {$result['imported']} waiting contact(s).");
    }

    /**
     * Guided permanent disconnect. Nothing is deleted: conversations and
     * messages stay readable, but inbound routing stops.
     */
    public function disconnect(Request $request, WhatsappBusinessAccount $waba): RedirectResponse
    {
        $workspaceId = $this->authorizeWaba($request, $waba);

        $waba->update([
            'coexistence_status' => WhatsappBusinessAccount::COEXISTENCE_DISCONNECTED,
            'coexistence_disconnected_at' => now(),
            'coexistence_disconnect_reason' => 'manual',
            'status' => 'inactive',
        ]);

        ChannelAccount::where('workspace_id', $workspaceId)
            ->where('channel', 'whatsapp')
            ->where('business_account_id', $waba->waba_id)
            ->update(['status' => 'inactive']);

        $this->audit->log('whatsapp.coexistence.disconnected', $waba, null, [
            'workspace_id' => $workspaceId,
            'waba_id' => $waba->waba_id,
        ]);

        return back()->with('success', 'Disconnected in the CRM. To stop using Cloud API completely, open the WhatsApp Business app → Settings → Account → Business Platform → Disconnect Account. Your conversations are kept.');
    }

    private function authorizeWaba(Request $request, WhatsappBusinessAccount $waba): int
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        abort_unless((int) $waba->workspace_id === $workspaceId, 403);

        return $workspaceId;
    }
}
