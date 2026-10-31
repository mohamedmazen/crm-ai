<?php

namespace App\Modules\Leads\Jobs;

use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScoreEvent;
use App\Modules\Leads\Services\Scoring\BehavioralScorer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Nightly decay of behavioral scores for one workspace.
 *
 * A lead that has gone quiet should not keep the score it earned while it was
 * engaged, so we subtract points once per inactivity tier: -5 after a week, and
 * a further -15 after a month. Both tiers are idempotent — a decay event only
 * counts if it was written after the lead's last activity, and any positive
 * event moves that marker forward, which re-arms the tiers for the next window.
 *
 * Queued per workspace (mirroring {@see RescoreWorkspaceLeadsJob}) so a large
 * tenant cannot hold the scheduler open.
 */
class DecayLeadScoresJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $workspaceId) {}

    /** One decay pass per workspace in flight. */
    public function uniqueId(): string
    {
        return 'decay-'.$this->workspaceId;
    }

    public function handle(BehavioralScorer $scorer): void
    {
        $decayed = 0;

        // Won and lost deals are settled; decay would only muddy their history.
        Lead::where('workspace_id', $this->workspaceId)
            ->whereNull('won_at')
            ->whereNull('lost_at')
            ->chunkById(200, function (Collection $leads) use ($scorer, &$decayed) {
                // One query for the whole chunk instead of two per lead.
                $existing = LeadScoreEvent::whereIn('lead_id', $leads->pluck('id'))
                    ->whereIn('event', ['inactivity_7d', 'inactivity_30d'])
                    ->get()
                    ->groupBy('lead_id');

                foreach ($leads as $lead) {
                    $tier = $this->dueTier($lead, $existing->get($lead->id, collect()));

                    if ($tier === null) {
                        continue;
                    }

                    $scorer->record(
                        $lead,
                        $tier,
                        'No engagement for '.($tier === 'inactivity_30d' ? '30' : '7').' days',
                    );
                    $decayed++;
                }
            });

        Log::info('Decayed inactive lead scores', [
            'workspace_id' => $this->workspaceId,
            'decayed' => $decayed,
        ]);
    }

    /**
     * The decay tier this lead is due, or null when neither applies.
     *
     * @param  Collection<int, LeadScoreEvent>  $existing
     */
    private function dueTier(Lead $lead, Collection $existing): ?string
    {
        $reference = $lead->last_activity_at ?? $lead->created_at;

        if (! $reference) {
            return null;
        }

        $inactiveDays = (int) $reference->diffInDays(now());

        $firedSinceActivity = fn (string $tier): bool => $existing
            ->where('event', $tier)
            ->contains(fn (LeadScoreEvent $event) => $event->created_at->greaterThan($reference));

        if ($inactiveDays >= 30 && ! $firedSinceActivity('inactivity_30d')) {
            return 'inactivity_30d';
        }

        if ($inactiveDays >= 7 && ! $firedSinceActivity('inactivity_7d')) {
            return 'inactivity_7d';
        }

        return null;
    }
}
