<?php

namespace App\Modules\Whatsapp\Services;

use App\Models\Workspace;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Services\ContactService;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappCoexistencePendingContact;
use Illuminate\Support\Facades\Log;

/**
 * Imports contacts that Meta sends for a coexistence number (address-book sync
 * and history threads) without ever exceeding the workspace's plan contact cap.
 *
 * The import is silent: ContactService is called with
 * `$dispatchCreatedEvent = false`, so no contact.created automations or
 * outbound webhooks fire for thousands of historical records. Contacts that do
 * not fit are staged in `whatsapp_coexistence_pending_contacts` instead of
 * being dropped — the soft-landing rule (never delete, only block new rows).
 */
class WhatsappCoexistenceContactImporter
{
    /**
     * Remaining contact slots per workspace for the lifetime of this importer.
     * Null means the plan has no cap. Decrementing locally avoids re-counting
     * the table for every contact in a large history chunk.
     *
     * @var array<int, int|null>
     */
    private array $capacity = [];

    public function __construct(private readonly ContactService $contacts) {}

    /**
     * Import one contact, staging it when the workspace is at its contact cap.
     *
     * @param  array{phone_number?: string|null, user_id?: string|null, username?: string|null, first_name?: string|null, full_name?: string|null}  $contact
     */
    public function import(
        WhatsappBusinessAccount $waba,
        array $contact,
        string $source = 'history_sync',
    ): ?Contact {
        $workspaceId = (int) $waba->workspace_id;
        $phone = $this->normalizePhone($contact['phone_number'] ?? null);
        $userId = $this->normalizeUserId($contact['user_id'] ?? null);

        if ($phone === null && $userId === null) {
            return null;
        }

        // A contact that is already in the CRM is an update, never a new row,
        // so it is allowed even when the workspace is over its cap.
        if ($this->contactExists($workspaceId, $phone, $userId)) {
            return $this->upsert($workspaceId, $phone, $userId, $contact);
        }

        if (! $this->hasCapacity($workspaceId)) {
            $this->stage($waba, $phone, $userId, $contact, $source);

            return null;
        }

        $this->consumeCapacity($workspaceId);

        return $this->upsert($workspaceId, $phone, $userId, $contact);
    }

    /**
     * Try to import every staged contact for a workspace. Called after an
     * upgrade or after contacts are removed, so nothing waits forever.
     *
     * @return array{imported: int, remaining: int}
     */
    public function retryPending(Workspace $workspace): array
    {
        $workspaceId = (int) $workspace->id;
        $imported = 0;

        $pending = WhatsappCoexistencePendingContact::where('workspace_id', $workspaceId)
            ->where('status', WhatsappCoexistencePendingContact::STATUS_PENDING)
            ->orderBy('id')
            ->get();

        foreach ($pending as $row) {
            if (! $this->hasCapacity($workspaceId)) {
                break;
            }

            $waba = WhatsappBusinessAccount::where('workspace_id', $workspaceId)
                ->where('waba_id', $row->waba_id)
                ->first();

            if (! $waba) {
                $row->update(['status' => WhatsappCoexistencePendingContact::STATUS_FAILED, 'reason' => 'waba_missing']);

                continue;
            }

            $contact = $this->import($waba, [
                'phone_number' => $row->phone,
                'user_id' => $row->wa_user_id,
                'full_name' => $row->display_name,
            ], (string) ($row->source ?: 'history_sync'));

            if ($contact === null) {
                continue;
            }

            $row->update([
                'status' => WhatsappCoexistencePendingContact::STATUS_IMPORTED,
                'imported_contact_id' => $contact->id,
                'reason' => null,
            ]);
            $imported++;
        }

        $remaining = WhatsappCoexistencePendingContact::where('workspace_id', $workspaceId)
            ->where('status', WhatsappCoexistencePendingContact::STATUS_PENDING)
            ->count();

        return ['imported' => $imported, 'remaining' => $remaining];
    }

    /**
     * Whether the workspace has room for one more contact under its plan.
     */
    public function hasCapacity(int $workspaceId): bool
    {
        $remaining = $this->remainingCapacity($workspaceId);

        return $remaining === null || $remaining > 0;
    }

    /**
     * @param  array{phone_number?: string|null, user_id?: string|null, username?: string|null, first_name?: string|null, full_name?: string|null}  $contact
     */
    private function upsert(int $workspaceId, ?string $phone, ?string $userId, array $contact): Contact
    {
        $name = $this->cleanName($contact['first_name'] ?? null)
            ?? $this->cleanName($contact['full_name'] ?? null);

        return $this->contacts->upsert($workspaceId, array_filter([
            'phone_e164' => $phone,
            'wa_user_id' => $userId,
            'wa_username' => $this->cleanName($contact['username'] ?? null),
            'first_name' => $name,
            'opt_in_whatsapp' => true,
            'source' => 'whatsapp_coexistence',
        ], fn ($value) => $value !== null && $value !== ''), dispatchCreatedEvent: false);
    }

    private function contactExists(int $workspaceId, ?string $phone, ?string $userId): bool
    {
        return Contact::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where(function ($query) use ($phone, $userId) {
                if ($phone !== null) {
                    $query->where('phone_e164', $phone);
                }

                if ($userId !== null) {
                    $query->orWhere('wa_user_id', $userId);
                }
            })
            ->exists();
    }

    /**
     * @param  array{phone_number?: string|null, user_id?: string|null, username?: string|null, first_name?: string|null, full_name?: string|null}  $contact
     */
    private function stage(
        WhatsappBusinessAccount $waba,
        ?string $phone,
        ?string $userId,
        array $contact,
        string $source,
    ): void {
        WhatsappCoexistencePendingContact::updateOrCreate(
            [
                'workspace_id' => $waba->workspace_id,
                'phone' => $this->stagingKey($phone, $userId),
            ],
            [
                'waba_id' => $waba->waba_id,
                'display_name' => $this->cleanName($contact['full_name'] ?? null)
                    ?? $this->cleanName($contact['first_name'] ?? null),
                'wa_user_id' => $userId,
                'source' => $source,
                'status' => WhatsappCoexistencePendingContact::STATUS_PENDING,
                'reason' => 'plan_contact_limit',
                'payload' => $contact,
            ],
        );

        Log::info('whatsapp.coexistence.contact_staged', [
            'workspace_id' => $waba->workspace_id,
            'waba_id' => $waba->waba_id,
            'source' => $source,
        ]);
    }

    private function remainingCapacity(int $workspaceId): ?int
    {
        if (array_key_exists($workspaceId, $this->capacity)) {
            return $this->capacity[$workspaceId];
        }

        $workspace = Workspace::with('client')->find($workspaceId);
        $limit = $workspace?->client?->effectivePlan()?->limitValue('max_contacts');

        if ($limit === null) {
            return $this->capacity[$workspaceId] = null;
        }

        $used = Contact::where('workspace_id', $workspaceId)->count();

        return $this->capacity[$workspaceId] = max(0, (int) $limit - $used);
    }

    private function consumeCapacity(int $workspaceId): void
    {
        $remaining = $this->capacity[$workspaceId] ?? null;

        if (is_int($remaining) && $remaining > 0) {
            $this->capacity[$workspaceId] = $remaining - 1;
        }
    }

    /**
     * The staging table only stores phone numbers; users who hide their number
     * are keyed by a stable hash of their business-scoped user ID instead.
     */
    private function stagingKey(?string $phone, ?string $userId): string
    {
        if ($phone !== null && $phone !== '') {
            return mb_substr($phone, 0, 32);
        }

        return 'wa:'.substr(hash('sha256', (string) $userId), 0, 28);
    }

    private function normalizePhone(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        return '+'.ltrim(trim($phone), '+');
    }

    private function normalizeUserId(?string $userId): ?string
    {
        if (! is_string($userId) || trim($userId) === '') {
            return null;
        }

        return mb_substr(trim($userId), 0, 64);
    }

    private function cleanName(?string $name): ?string
    {
        if (! is_string($name) || trim($name) === '') {
            return null;
        }

        return mb_substr(trim($name), 0, 128);
    }
}
