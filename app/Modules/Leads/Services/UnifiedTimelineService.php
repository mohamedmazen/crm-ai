<?php

namespace App\Modules\Leads\Services;

use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadTask;
use App\Modules\Shared\Models\Message;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation-intelligence feed for one lead: every message, campaign send,
 * commerce order and follow-up task, merged into a single newest-first timeline.
 *
 * The lead's linked contact is the join key between the CRM tables and the
 * messaging / broadcasting / commerce modules, so it is resolved once and
 * reused as the filter for all of them.
 *
 * Each entry is shaped for rendering, not for round-tripping back into the
 * source model: the frontend only needs an id, a kind, a headline and a moment.
 */
class UnifiedTimelineService
{
    /** Hard cap so a very chatty contact cannot make the lead modal heavy. */
    public const LIMIT = 100;

    /**
     * @return list<array{
     *     id: string,
     *     type: string,
     *     title: ?string,
     *     body: ?string,
     *     direction: ?string,
     *     channel: ?string,
     *     meta: array<string, mixed>,
     *     occurred_at: ?string
     * }>
     */
    public function forLead(Lead $lead): array
    {
        $contactId = $lead->contact?->id;

        $items = $contactId
            ? array_merge(
                $this->messages($lead->workspace_id, $contactId),
                $this->campaigns($contactId),
                $this->orders($contactId),
            )
            : [];

        $items = array_merge($items, $this->tasks($lead));

        usort($items, fn (array $a, array $b): int => strcmp((string) $b['occurred_at'], (string) $a['occurred_at']));

        return array_slice($items, 0, self::LIMIT);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function messages(int $workspaceId, int $contactId): array
    {
        return Message::query()
            ->whereHas('conversation', fn ($query) => $query
                ->where('workspace_id', $workspaceId)
                ->where('contact_id', $contactId))
            ->orderByDesc('sent_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Message $message): array => [
                'id' => 'message:'.$message->id,
                'type' => 'message',
                'title' => null,
                'body' => $message->body,
                'direction' => $message->direction,
                'channel' => $message->channel,
                'meta' => [
                    'status' => $message->status,
                    'message_type' => $message->type,
                ],
                'occurred_at' => $message->sent_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function campaigns(int $contactId): array
    {
        if (! Schema::hasTable('campaign_recipients')) {
            return [];
        }

        return CampaignRecipient::with('campaign:id,name,channel')
            ->where('contact_id', $contactId)
            ->orderByDesc('sent_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (CampaignRecipient $recipient): array => [
                'id' => 'campaign:'.$recipient->id,
                'type' => 'campaign',
                'title' => $recipient->campaign?->name,
                'body' => null,
                'direction' => 'out',
                'channel' => $recipient->campaign?->channel,
                'meta' => ['status' => $this->campaignStatus($recipient)],
                // The latest engagement is the moment worth showing, not the send.
                'occurred_at' => ($recipient->clicked_at ?? $recipient->read_at ?? $recipient->sent_at)?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function orders(int $contactId): array
    {
        if (! Schema::hasTable('ecommerce_orders')) {
            return [];
        }

        return EcommerceOrder::where('contact_id', $contactId)
            ->whereNotNull('placed_at')
            ->orderByDesc('placed_at')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (EcommerceOrder $order): array => [
                'id' => 'order:'.$order->id,
                'type' => 'order',
                'title' => $order->number ?: $order->external_order_id,
                'body' => null,
                'direction' => 'out',
                'channel' => $order->platform,
                'meta' => [
                    'total' => (float) $order->total,
                    'currency' => $order->currency,
                    'status' => $order->financial_status ?? $order->status,
                ],
                'occurred_at' => $order->placed_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tasks(Lead $lead): array
    {
        return LeadTask::where('lead_id', $lead->id)
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (LeadTask $task): array => [
                'id' => 'task:'.$task->id,
                'type' => 'task',
                'title' => $task->title,
                'body' => $task->description,
                'direction' => null,
                'channel' => null,
                'meta' => [
                    'is_completed' => $task->isCompleted(),
                    'is_overdue' => $task->isOverdue(),
                    'due_at' => $task->due_at?->toIso8601String(),
                ],
                'occurred_at' => ($task->completed_at ?? $task->due_at ?? $task->created_at)?->toIso8601String(),
            ])
            ->all();
    }

    /** How far the contact engaged with the campaign, most engaged first. */
    private function campaignStatus(CampaignRecipient $recipient): string
    {
        return match (true) {
            $recipient->clicked_at !== null => 'clicked',
            $recipient->read_at !== null => 'read',
            $recipient->delivered_at !== null => 'delivered',
            $recipient->failed_reason !== null => 'failed',
            default => $recipient->status,
        };
    }
}
