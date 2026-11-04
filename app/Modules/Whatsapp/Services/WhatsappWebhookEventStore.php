<?php

namespace App\Modules\Whatsapp\Services;

use App\Modules\Whatsapp\Jobs\ProcessInboundMessageJob;
use App\Modules\Whatsapp\Models\WhatsappBusinessAccount;
use App\Modules\Whatsapp\Models\WhatsappWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Persists every inbound webhook entry before it is queued, so failures can be
 * inspected and replayed instead of being lost with the HTTP request.
 *
 * The row is also the dead-letter record: the job moves it to `processed` or
 * `failed`, and `whatsapp:replay-webhook-events` re-dispatches failed rows.
 */
class WhatsappWebhookEventStore
{
    /**
     * @param  array<string, mixed>  $entry
     */
    public function record(
        array $entry,
        string $object,
        ?int $workspaceId,
        ?string $eventKey,
        ?string $phoneNumberId = null,
    ): ?WhatsappWebhookEvent {
        $wabaId = isset($entry['id']) ? (string) $entry['id'] : null;

        if ($workspaceId === null && $wabaId !== null) {
            $workspaceId = WhatsappBusinessAccount::where('waba_id', $wabaId)->value('workspace_id');
        }

        $eventKey ??= $this->fallbackKey($entry);

        try {
            return WhatsappWebhookEvent::create([
                'workspace_id' => $workspaceId,
                'waba_id' => $wabaId,
                'phone_number_id' => $phoneNumberId,
                'event_key' => $eventKey,
                'object' => $object,
                'event_type' => $this->eventType($entry),
                'payload' => $entry,
                'processing_status' => 'received',
                'received_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // The same entry already arrived through another endpoint
            // (global vs. per-WABA). It was or will be processed once.
            return null;
        }
    }

    public function replay(WhatsappWebhookEvent $event): void
    {
        $event->forceFill([
            'processing_status' => 'received',
            'error_message' => null,
            'processed_at' => null,
        ])->save();

        ProcessInboundMessageJob::dispatch(
            ['object' => $event->object, 'entry' => [$event->payload]],
            '',
            $event->id,
        )->onQueue('whatsapp');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function eventType(array $entry): string
    {
        foreach ($entry['changes'] ?? [] as $change) {
            $value = $change['value'] ?? [];

            if (! empty($value['messages'])) {
                return 'messages';
            }

            if (! empty($value['statuses'])) {
                return 'statuses';
            }

            if (! empty($change['field'])) {
                return mb_substr((string) $change['field'], 0, 32);
            }
        }

        return 'unknown';
    }

    /**
     * Entries with no identifiable message/status ids are processed fail-open;
     * they still need a stable key so a re-delivery does not duplicate the row.
     *
     * @param  array<string, mixed>  $entry
     */
    private function fallbackKey(array $entry): string
    {
        $blob = json_encode($entry, JSON_UNESCAPED_UNICODE);

        if ($blob === false || $blob === '') {
            return hash('sha256', Str::uuid()->toString());
        }

        return hash('sha256', $blob);
    }
}
