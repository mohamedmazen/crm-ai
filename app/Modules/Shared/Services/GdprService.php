<?php

namespace App\Modules\Shared\Services;

use App\Models\Client;
use App\Models\ClientSetting;
use App\Models\SystemSetting;
use App\Models\Workspace;
use App\Modules\Leads\Models\Lead;
use App\Modules\Shared\Jobs\PurgeErasureRequestJob;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\GdprErasureRequest;
use App\Modules\Shared\Models\Message;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Data-protection operations: erasure requests, retention and DPA documents.
 *
 * Erasure is deliberately split into a request and a scheduled execution. A
 * workspace admin files the request, the data subject gets a 30-day window in
 * which it can be cancelled, and {@see PurgeErasureRequestJob}
 * performs the irreversible deletion when the deadline passes.
 */
class GdprService
{
    /** Days between accepting a request and deleting the data. */
    public const ERASURE_GRACE_DAYS = 30;

    /** Default age (days) after which generated export archives are deleted. */
    public const DEFAULT_EXPORT_RETENTION_DAYS = 7;

    public function requestErasure(int $workspaceId, string $email, ?int $userId = null, ?string $notes = null): GdprErasureRequest
    {
        $email = strtolower(trim($email));

        $existing = GdprErasureRequest::forWorkspace($workspaceId)
            ->pending()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->first();

        if ($existing) {
            return $existing;
        }

        return GdprErasureRequest::create([
            'workspace_id' => $workspaceId,
            'requested_by' => $userId,
            'email' => $email,
            'status' => GdprErasureRequest::STATUS_PENDING,
            'due_at' => now()->addDays(self::ERASURE_GRACE_DAYS),
            'notes' => $notes,
        ]);
    }

    public function cancel(GdprErasureRequest $request): bool
    {
        if (! $request->isPending()) {
            return false;
        }

        $request->forceFill([
            'status' => GdprErasureRequest::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        return true;
    }

    /**
     * Delete / anonymise every trace of an email address in one workspace.
     *
     * Inbox conversations and contacts are removed outright. Leads are
     * anonymised instead of deleted so financial records (quotes, invoices,
     * call logs) keep their referential integrity while the personal data is
     * gone.
     *
     * @return array{contacts: int, conversations: int, messages: int, leads: int}
     */
    public function execute(GdprErasureRequest $request): array
    {
        if (! $request->isPending()) {
            return ['contacts' => 0, 'conversations' => 0, 'messages' => 0, 'leads' => 0];
        }

        return DB::transaction(function () use ($request): array {
            $workspaceId = (int) $request->workspace_id;
            $email = strtolower(trim((string) $request->email));

            $contactIds = Contact::withTrashed()
                ->where('workspace_id', $workspaceId)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->pluck('id');

            $conversationIds = Conversation::where('workspace_id', $workspaceId)
                ->whereIn('contact_id', $contactIds)
                ->pluck('id');

            $messages = $conversationIds->isEmpty()
                ? 0
                : Message::whereIn('conversation_id', $conversationIds)->delete();

            $conversations = $conversationIds->isEmpty()
                ? 0
                : Conversation::whereIn('id', $conversationIds)->delete();

            $contacts = $contactIds->isEmpty()
                ? 0
                : Contact::withTrashed()->whereIn('id', $contactIds)->forceDelete();

            // Keep the deal pipeline intact, but strip identifiers.
            $leads = Lead::where('workspace_id', $workspaceId)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->update([
                    'name' => __('Erased contact'),
                    'email' => null,
                    'phone' => null,
                    'website' => null,
                    'address' => null,
                    'city' => null,
                    'country' => null,
                    'lat' => null,
                    'lng' => null,
                ]);

            $request->forceFill([
                'status' => GdprErasureRequest::STATUS_COMPLETED,
                'completed_at' => now(),
            ])->save();

            return [
                'contacts' => (int) $contacts,
                'conversations' => (int) $conversations,
                'messages' => (int) $messages,
                'leads' => (int) $leads,
            ];
        });
    }

    /**
     * Requests whose grace period has elapsed and that still need executing.
     *
     * @return Collection<int, GdprErasureRequest>
     */
    public function dueRequests(): Collection
    {
        return GdprErasureRequest::due()->orderBy('due_at')->get();
    }

    /**
     * Generate the Data Processing Agreement for a client, ready to download.
     */
    public function dpa(Client $client): PdfDocument
    {
        return Pdf::loadView('pdf.dpa', [
            'client' => $client,
            'appName' => SystemSetting::get('app_name') ?: config('app.name'),
            'supportEmail' => SystemSetting::get('support_email') ?: config('mail.from.address', 'support@example.com'),
            'generatedAt' => Carbon::now(),
            'retentionDays' => self::DEFAULT_EXPORT_RETENTION_DAYS,
        ])->setPaper('a4');
    }

    /**
     * Delete generated export archives past their retention window.
     *
     * A workspace can override the platform default from its privacy settings;
     * 0 disables the sweep for that workspace. Passing $days explicitly forces
     * the same window for every archive (used by `gdpr:process --retention-days`).
     */
    public function applyExportRetention(?int $days = null): int
    {
        $globalDays = $days ?? (int) SystemSetting::get('privacy.export_retention_days', self::DEFAULT_EXPORT_RETENTION_DAYS);
        $deleted = 0;

        foreach (Storage::allFiles('exports') as $path) {
            $retention = $days ?? $this->workspaceRetentionDays($path, $globalDays);

            if ($retention <= 0) {
                continue;
            }

            if (Storage::lastModified($path) <= now()->subDays($retention)->getTimestamp()) {
                Storage::delete($path);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Retention window for the workspace that owns an export path
     * (`exports/{workspaceId}/...`), falling back to the platform default.
     */
    private function workspaceRetentionDays(string $path, int $default): int
    {
        $segments = explode('/', $path);
        $workspaceId = isset($segments[1]) ? (int) $segments[1] : 0;

        if ($workspaceId <= 0) {
            return $default;
        }

        $clientId = Workspace::whereKey($workspaceId)->value('client_id');

        if (! $clientId) {
            return $default;
        }

        $configured = ClientSetting::get((int) $clientId, 'export_retention_days');

        return $configured === null ? $default : (int) $configured;
    }
}
