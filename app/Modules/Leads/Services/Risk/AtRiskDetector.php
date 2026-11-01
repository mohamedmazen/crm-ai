<?php

namespace App\Modules\Leads\Services\Risk;

use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScoreEvent;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Flags leads that are drifting away, from evidence the app already stores.
 *
 * Deliberately deterministic: no model call, no cost, and the same lead always
 * produces the same reasons. AI is added on top of this elsewhere (the lead
 * coach consumes these reasons), never underneath it.
 *
 * Signals are only ever ORed into a capped score — a lead with no signals scores
 * zero — so a fresh lead created seconds ago is never reported as at risk.
 */
class AtRiskDetector
{
    /** Silence long enough that the lead has probably been forgotten. */
    public const STALE_AFTER_DAYS = 14;

    /** Sitting in one stage this long usually means the deal stalled. */
    public const STALLED_STAGE_AFTER_DAYS = 21;

    /** One day of grace, so "due today" is not itself a risk signal. */
    public const FOLLOW_UP_GRACE_DAYS = 1;

    private const POINTS_NO_ACTIVITY = 35;

    private const POINTS_SCORE_DECAY = 20;

    private const POINTS_OVERDUE_FOLLOW_UP = 25;

    private const POINTS_STALLED_STAGE = 15;

    private const POINTS_PAST_CLOSE_DATE = 15;

    private const POINTS_COLD_BAND = 10;

    /**
     * Risk for one lead.
     *
     * @param  bool  $inTerminalStage  The column the lead sits in is won/lost, so the deal is settled
     */
    public function analyze(Lead $lead, ?LeadScoreEvent $latestEvent = null, bool $inTerminalStage = false): LeadRisk
    {
        if ($this->isSettled($lead, $inTerminalStage)) {
            return LeadRisk::none();
        }

        $now = now();
        $reasons = [];

        $idleDays = $this->daysSince($lead->last_activity_at ?? $lead->created_at, $now);

        if ($idleDays !== null && $idleDays >= self::STALE_AFTER_DAYS) {
            $reasons[] = [
                'code' => 'no_activity',
                'points' => self::POINTS_NO_ACTIVITY,
                'days' => $idleDays,
                'detail' => "No activity for {$idleDays} days",
            ];
        }

        if ($latestEvent !== null && $latestEvent->points < 0) {
            $reasons[] = [
                'code' => 'score_decay',
                'points' => self::POINTS_SCORE_DECAY,
                'detail' => 'Engagement is decaying: '.($latestEvent->reason ?: $latestEvent->event),
            ];
        }

        if ($lead->next_follow_up_at !== null) {
            $overdueDays = $this->daysSince($lead->next_follow_up_at, $now);

            if ($overdueDays !== null && $overdueDays >= self::FOLLOW_UP_GRACE_DAYS) {
                $reasons[] = [
                    'code' => 'overdue_follow_up',
                    'points' => self::POINTS_OVERDUE_FOLLOW_UP,
                    'days' => $overdueDays,
                    'detail' => "Follow-up is {$overdueDays} day(s) overdue",
                ];
            }
        }

        $stageDays = $this->daysSince($lead->stage_changed_at ?? $lead->created_at, $now);

        if ($stageDays !== null && $stageDays >= self::STALLED_STAGE_AFTER_DAYS) {
            $reasons[] = [
                'code' => 'stalled_stage',
                'points' => self::POINTS_STALLED_STAGE,
                'days' => $stageDays,
                'detail' => "In the same stage for {$stageDays} days",
            ];
        }

        if ($lead->expected_close_date !== null && $lead->expected_close_date->lt($now->copy()->startOfDay())) {
            $lateDays = $this->daysSince($lead->expected_close_date->copy()->startOfDay(), $now);

            $reasons[] = [
                'code' => 'past_close_date',
                'points' => self::POINTS_PAST_CLOSE_DATE,
                'days' => $lateDays ?? 0,
                'detail' => 'Expected close date passed '.($lateDays ?? 0).' day(s) ago',
            ];
        }

        if ($lead->score_band === 'cold') {
            $reasons[] = [
                'code' => 'cold_band',
                'points' => self::POINTS_COLD_BAND,
                'detail' => 'Currently scored as a cold lead',
            ];
        }

        return $this->riskFrom($reasons);
    }

    /**
     * Risk for a single lead, fetching its newest score event. Used by the lead
     * detail modal, where batching would be one query for one lead anyway.
     */
    public function analyzeOne(Lead $lead): LeadRisk
    {
        $latest = LeadScoreEvent::where('lead_id', $lead->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $this->analyze($lead, $latest);
    }

    /**
     * Risk for a whole board column or page, in two queries rather than two per
     * lead: the newest score event per lead is read in one pass and grouped.
     *
     * @param  EloquentCollection<int, Lead>|Collection<int, Lead>  $leads
     * @return array<int, LeadRisk> Keyed by lead id
     */
    public function analyzeMany(EloquentCollection|Collection $leads, bool $inTerminalStage = false): array
    {
        if ($leads->isEmpty()) {
            return [];
        }

        $latest = LeadScoreEvent::whereIn('lead_id', $leads->pluck('id')->all())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->keyBy('lead_id');

        $risks = [];

        foreach ($leads as $lead) {
            $risks[(int) $lead->id] = $this->analyze($lead, $latest->get($lead->id), $inTerminalStage);
        }

        return $risks;
    }

    /** Won, lost, or parked in a won/lost column: nothing left to rescue. */
    private function isSettled(Lead $lead, bool $inTerminalStage): bool
    {
        return $lead->won_at !== null
            || $lead->lost_at !== null
            || $inTerminalStage
            || (bool) $lead->stage?->is_won
            || (bool) $lead->stage?->is_lost;
    }

    /** Whole days elapsed between two moments, or null when the reference is missing. */
    private function daysSince(?\DateTimeInterface $since, \DateTimeInterface $now): ?int
    {
        if ($since === null) {
            return null;
        }

        $seconds = $now->getTimestamp() - $since->getTimestamp();

        return $seconds <= 0 ? 0 : (int) floor($seconds / 86400);
    }

    /**
     * @param  array<int, array{code: string, points: int, detail: string, days?: int}>  $reasons
     */
    private function riskFrom(array $reasons): LeadRisk
    {
        if ($reasons === []) {
            return LeadRisk::none();
        }

        // Strongest evidence first: the badge shows one sentence.
        usort($reasons, fn (array $a, array $b) => $b['points'] <=> $a['points']);

        $score = min(100, array_sum(array_column($reasons, 'points')));

        $level = match (true) {
            $score >= 50 => LeadRisk::LEVEL_HIGH,
            $score >= 25 => LeadRisk::LEVEL_MEDIUM,
            default => LeadRisk::LEVEL_LOW,
        };

        return new LeadRisk($level, $score, $reasons);
    }
}
