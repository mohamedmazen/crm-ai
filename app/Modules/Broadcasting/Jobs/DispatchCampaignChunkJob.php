<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * Sends one page of a campaign, then chains the next page.
 *
 * Previously every page of a campaign was dispatched up front, so a single
 * campaign kept dozens of pages in flight (~200 messages/second) and starved
 * every other tenant on the broadcast queue. Now exactly one page per campaign
 * is queued at a time: a page schedules its messages at a paced interval and
 * only chains the next page after that interval.
 */
class DispatchCampaignChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Recipients per page. Keeps each page's delayed message jobs bounded. */
    public const BATCH_SIZE = 1000;

    /**
     * Cursor of the last recipient already dispatched. Declared outside the
     * constructor so chunks queued before the progressive dispatcher (which have
     * no such property in their payload) restore the default 0.
     */
    public int $afterId = 0;

    /**
     * Legacy payload field. Chunks queued before the progressive dispatcher keep
     * it in their serialized payload; it is ignored because the cursor re-reads
     * queued recipients from the database.
     *
     * @var array<int, int>|null
     */
    public ?array $contactIds = null;

    /**
     * @param  array<int, int>|null  $contactIds
     */
    public function __construct(
        public readonly int $campaignId,
        int $afterId = 0,
        ?array $contactIds = null,
    ) {
        $this->afterId = $afterId;
        $this->contactIds = $contactIds;
    }

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);

        // Only an actively sending campaign dispatches. Pausing, cancelling or
        // completing stops the chain at the next page.
        if (! $campaign || $campaign->status !== 'sending') {
            return;
        }

        $recipients = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->where('id', '>', $this->afterId)
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get(['id', 'contact_id']);

        if ($recipients->isEmpty()) {
            return;
        }

        $interval = self::messageIntervalMsFor($campaign);

        $this->dispatchBatch($campaign, $recipients, $interval);

        if ($recipients->count() < self::BATCH_SIZE) {
            return;
        }

        // Chain the next page only after this page's paced window, otherwise the
        // delayed messages would pile up exactly like the old behaviour.
        self::dispatch($campaign->id, (int) $recipients->last()->id)
            ->onQueue('broadcast')
            ->delay(now()->addSeconds(self::pacingSeconds($recipients->count(), $interval)));
    }

    /**
     * @param  Collection<int, CampaignRecipient>  $recipients
     */
    private function dispatchBatch(Campaign $campaign, Collection $recipients, int $intervalMs): void
    {
        $workspaceId = (int) $campaign->workspace_id;

        foreach ($recipients->values() as $index => $recipient) {
            SendCampaignMessageJob::dispatch($campaign->id, (int) $recipient->contact_id, $workspaceId)
                ->onQueue('broadcast')
                ->delay(now()->addMilliseconds($index * $intervalMs));
        }
    }

    /**
     * Spread one page over this campaign's share of the tenant message budget.
     */
    public static function pacingSeconds(int $count, int $intervalMs): int
    {
        return (int) ceil($count * $intervalMs / 1000);
    }

    /**
     * Wall-clock seconds one full page occupies at the current pacing.
     *
     * The finalizer uses this as its stall baseline: a healthy page keeps
     * touching recipient rows for that whole window, so silence beyond it means
     * the chain died with its worker.
     */
    public static function pageWindowSeconds(Campaign $campaign, int $count = self::BATCH_SIZE): int
    {
        return self::pacingSeconds($count, self::messageIntervalMsFor($campaign));
    }

    /**
     * The per-tenant limit is shared by every campaign the workspace has in
     * flight, so each one paces at its fair share. This keeps the queue
     * middleware from having to release most of the batch.
     */
    public static function messageIntervalMsFor(Campaign $campaign): int
    {
        $perMinute = max(1, (int) config('queue.tenant_limits.messages_per_minute', 120));

        $activeCampaigns = max(1, Campaign::where('workspace_id', $campaign->workspace_id)
            ->where('status', 'sending')
            ->count());

        $perMinutePerCampaign = max(1, (int) floor($perMinute / $activeCampaigns));

        // Never schedule faster than the old 10/s floor. The queue middleware
        // still enforces the hard per-tenant cap across campaigns.
        return max(100, (int) round(60000 / $perMinutePerCampaign));
    }
}
