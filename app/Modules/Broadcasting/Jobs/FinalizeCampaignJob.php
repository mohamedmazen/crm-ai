<?php

namespace App\Modules\Broadcasting\Jobs;

use App\Events\CampaignCompleted;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Support\Cache\TenantCache;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Finalises a campaign once all recipient sends have settled.
 *
 * Counts recipients still in the `queued` bucket; if any remain, it
 * re-schedules itself a minute later. Otherwise, marks the campaign
 * as `completed`, refreshes totals, and fires CampaignCompleted.
 */
class FinalizeCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 60;

    /** Used to bound max self-rescheduling (default ~7 days). */
    public function __construct(
        public readonly int $campaignId,
        public readonly int $attempt = 1,
    ) {}

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }

        // If user paused the campaign, don't auto-complete; wait for resume.
        if ($campaign->status === 'paused') {
            return;
        }

        // Already finalised
        if (in_array($campaign->status, ['completed', 'failed', 'draft'], true)) {
            $campaign->updateTotals();

            return;
        }

        $stillQueued = CampaignRecipient::where('campaign_id', $campaign->id)
            ->where('status', 'queued')
            ->count();

        // The per-tenant throttle caps large campaigns, so a million-recipient
        // send can legitimately run for days. Bound the polling well past that
        // instead of giving up (and mis-marking the campaign complete) at 24h.
        $maxAttempts = max(1, (int) config('queue.tenant_limits.campaign_finalize_max_attempts', 10080));

        if ($stillQueued > 0 && $this->attempt < $maxAttempts) {
            // Self-heal a chunk chain that died with its worker (SIGKILL, reboot).
            $this->recoverStalledChain($campaign);

            // Re-schedule in 60s; keeps polling until every recipient settles.
            self::dispatch($campaign->id, $this->attempt + 1)
                ->onQueue('broadcast')
                ->delay(now()->addSeconds(60));

            return;
        }

        $totals = CampaignRecipient::where('campaign_id', $campaign->id)
            ->selectRaw('status, count(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status')
            ->toArray();

        $sent = (int) ($totals['sent'] ?? 0)
            + (int) ($totals['delivered'] ?? 0)
            + (int) ($totals['read'] ?? 0);
        $failed = (int) ($totals['failed'] ?? 0);
        $total = $sent + $failed + (int) ($totals['queued'] ?? 0);

        $newStatus = ($total === 0 || ($failed > 0 && $sent === 0)) ? 'failed' : 'completed';

        // Atomic guard: only one concurrent worker may finalize the campaign.
        // If another worker already set the status, affected=0 and we skip the event.
        $affected = Campaign::where('id', $campaign->id)
            ->whereNotIn('status', ['completed', 'failed', 'draft'])
            ->update(['status' => $newStatus]);

        if ($affected === 0) {
            return;
        }

        $campaign->updateTotals();

        CampaignCompleted::dispatch($campaign->fresh());
    }

    /**
     * Re-kick a chunk chain whose worker was killed mid-campaign.
     *
     * Progressive dispatch keeps exactly one chunk per campaign in flight, so a
     * worker lost to SIGKILL or a reboot takes the next page with it and the
     * campaign would sit in `sending` forever. This is the safety net: if no
     * recipient has moved for longer than a page should take, queue the cursor
     * again from the first still-queued recipient.
     *
     * The chunk re-reads recipients by status, so already-sent contacts are
     * skipped and the retry is idempotent at the queue level.
     */
    private function recoverStalledChain(Campaign $campaign): void
    {
        $workspaceId = (int) $campaign->workspace_id;

        if ($campaign->status !== 'sending' || $workspaceId <= 0) {
            return;
        }

        $stallAfter = $this->stallThresholdSeconds($campaign);

        if ($this->lastChainActivity($campaign)->greaterThan(now()->subSeconds($stallAfter))) {
            return;
        }

        // Several finalizers can observe the same stall; at most one may kick
        // the chain per stall window. The claim lives on Redis in production,
        // so it is shared by every worker.
        $claimed = TenantCache::for($workspaceId)->add(
            'campaign-chain-recovery:'.$campaign->id,
            1,
            $stallAfter,
        );

        if (! $claimed) {
            return;
        }

        Log::channel('json')->warning('campaign.chain.recovered', [
            'workspace_id' => $workspaceId,
            'campaign_id' => $campaign->id,
            'stalled_for_seconds' => $stallAfter,
        ]);

        DispatchCampaignChunkJob::dispatch($campaign->id)->onQueue('broadcast');
    }

    /**
     * How long a page may take before its silence counts as a stall.
     *
     * A page keeps updating recipient rows for its whole pacing window, so the
     * baseline has to exceed that window; otherwise a large campaign sharing the
     * tenant budget with another one would look dead between two pages.
     */
    private function stallThresholdSeconds(Campaign $campaign): int
    {
        $pageWindow = DispatchCampaignChunkJob::pageWindowSeconds($campaign);

        return max(900, $pageWindow + 300);
    }

    private function lastChainActivity(Campaign $campaign): Carbon
    {
        $recipientActivity = CampaignRecipient::where('campaign_id', $campaign->id)
            ->max('updated_at');

        return collect([$campaign->updated_at, $recipientActivity])
            ->filter()
            ->map(fn ($value) => Carbon::parse($value))
            ->sortDesc()
            ->first() ?? now()->subYears(10);
    }
}
