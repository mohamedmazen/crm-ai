<?php

namespace App\Modules\Sales\Services;

use App\Events\SequenceCompleted;
use App\Events\SequenceEnrolled;
use App\Events\SequenceStepSent;
use App\Events\SequenceStopped;
use App\Modules\Automation\Models\WorkspaceBusinessHours;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Jobs\SendSequenceStepJob;
use App\Modules\Sales\Models\Sequence;
use App\Modules\Sales\Models\SequenceEnrollment;
use App\Modules\Sales\Models\SequenceEvent;
use App\Modules\Sales\Models\SequenceStep;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Schedules sequence sends, respecting business hours and stop rules.
 *
 * The engine owns the state machine; {@see SequenceMessageSender} owns delivery.
 * Every terminal transition emits a domain event (scalars only) so Phase 6's AI
 * SDR can react without depending on this module.
 */
class SequenceEngine
{
    /** How far ahead a dispatched enrollment is claimed to stop duplicate dispatch. */
    private const CLAIM_MINUTES = 10;

    public function __construct(private readonly SequenceMessageSender $sender) {}

    /**
     * Enroll a lead, starting from the first step.
     *
     * Re-enrolling a stopped or completed lead resets the enrollment instead of
     * failing, so an automation can put the same lead through a sequence again.
     */
    public function enroll(Sequence $sequence, Lead $lead, string $source = 'manual'): SequenceEnrollment
    {
        if ((int) $lead->workspace_id !== (int) $sequence->workspace_id) {
            throw new InvalidArgumentException('The lead and sequence belong to different workspaces.');
        }

        return DB::transaction(function () use ($sequence, $lead, $source) {
            $enrollment = SequenceEnrollment::firstOrNew([
                'sequence_id' => $sequence->id,
                'lead_id' => $lead->id,
            ]);

            if ($enrollment->exists && $enrollment->isActive()) {
                return $enrollment;
            }

            $firstStep = $sequence->steps()->orderBy('position')->first();

            $enrollment->fill([
                'current_step' => 0,
                'status' => SequenceEnrollment::STATUS_ACTIVE,
                'stop_reason' => null,
                'next_run_at' => $firstStep
                    ? now()->addHours(max(0, (int) $firstStep->delay_hours))
                    : null,
            ])->save();

            SequenceEnrolled::dispatch(
                (int) $sequence->workspace_id,
                (int) $sequence->id,
                (int) $enrollment->id,
                (int) $lead->id,
                $source,
            );

            if (! $firstStep) {
                $this->complete($enrollment, 'empty');
            }

            return $enrollment;
        });
    }

    /**
     * Claim and queue every enrollment whose next send is due.
     *
     * Claiming pushes `next_run_at` forward so a second scheduler tick cannot
     * queue the same enrollment twice while the first job is still waiting.
     */
    public function processDue(int $limit = 200): int
    {
        $due = SequenceEnrollment::query()
            ->active()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(max(1, $limit))
            ->get();

        $dispatched = 0;

        foreach ($due as $enrollment) {
            $claimed = SequenceEnrollment::whereKey($enrollment->id)
                ->active()
                ->whereNotNull('next_run_at')
                ->where('next_run_at', '<=', now())
                ->update(['next_run_at' => now()->addMinutes(self::CLAIM_MINUTES)]);

            if ($claimed === 1) {
                SendSequenceStepJob::dispatch((int) $enrollment->id);
                $dispatched++;
            }
        }

        return $dispatched;
    }

    /**
     * Send the next step of an enrollment, or stop/complete it.

     *
     * @throws \Throwable so the queue can retry transient provider failures
     */
    public function sendNextStep(int $enrollmentId): void
    {
        $enrollment = SequenceEnrollment::with(['sequence.steps', 'lead.contact'])->find($enrollmentId);

        if (! $enrollment || ! $enrollment->isActive()) {
            return;
        }

        $sequence = $enrollment->sequence;

        if (! $sequence) {
            $this->stop($enrollment, 'sequence_deleted');

            return;
        }

        if ($sequence->status !== Sequence::STATUS_ACTIVE) {
            $this->stop($enrollment, 'sequence_'.$sequence->status);

            return;
        }

        $step = $enrollment->nextStep();

        if (! $step) {
            $this->complete($enrollment);

            return;
        }

        if ($sequence->respect_hours && ! $this->isOpen((int) $sequence->workspace_id)) {
            $enrollment->update(['next_run_at' => $this->nextOpenAt((int) $sequence->workspace_id)]);

            return;
        }

        $messageId = $this->sender->send($sequence, $step, $enrollment);

        $this->recordSent($enrollment, $step);
        $this->advance($enrollment, $step);

        SequenceStepSent::dispatch(
            (int) $sequence->workspace_id,
            (int) $sequence->id,
            (int) $enrollment->id,
            (int) $enrollment->lead_id,
            (int) $step->position,
            $step->channel,
            $messageId,
        );

        if ($enrollment->nextStep() === null) {
            $this->complete($enrollment);
        }
    }

    public function stop(SequenceEnrollment $enrollment, string $reason): void
    {
        if (! $enrollment->isActive()) {
            return;
        }

        $enrollment->update([
            'status' => SequenceEnrollment::STATUS_STOPPED,
            'stop_reason' => mb_substr($reason, 0, 64),
            'next_run_at' => null,
        ]);

        $sequence = $enrollment->sequence;

        if (! $sequence) {
            return;
        }

        SequenceStopped::dispatch(
            (int) $sequence->workspace_id,
            (int) $sequence->id,
            (int) $enrollment->id,
            (int) $enrollment->lead_id,
            $reason,
        );
    }

    public function complete(SequenceEnrollment $enrollment, string $reason = 'exhausted'): void
    {
        $enrollment->update([
            'status' => SequenceEnrollment::STATUS_COMPLETED,
            'stop_reason' => null,
            'next_run_at' => null,
        ]);

        $sequence = $enrollment->sequence;

        if (! $sequence) {
            return;
        }

        SequenceCompleted::dispatch(
            (int) $sequence->workspace_id,
            (int) $sequence->id,
            (int) $enrollment->id,
            (int) $enrollment->lead_id,
            $reason,
        );
    }

    /**
     * Stop every active enrollment for a lead where the sequence opted into the
     * given rule (`stop_on_reply` / `stop_on_won`).
     *
     * @return Collection<int, SequenceEnrollment> the enrollments that were stopped
     */
    public function stopForLead(int $workspaceId, int $leadId, string $reason, string $flag = 'stop_on_reply'): Collection
    {
        $enrollments = SequenceEnrollment::query()
            ->active()
            ->where('lead_id', $leadId)
            ->whereHas('sequence', function ($query) use ($workspaceId, $flag) {
                $query->where('workspace_id', $workspaceId)->where($flag, true);
            })
            ->with('sequence')
            ->get();

        foreach ($enrollments as $enrollment) {
            $this->stop($enrollment, $reason);
        }

        return $enrollments;
    }

    public function isOpen(int $workspaceId): bool
    {
        return WorkspaceBusinessHours::forWorkspace($workspaceId)->isOpenAt();
    }

    /** The first open moment within the next week, or 24h from now as a fallback. */
    public function nextOpenAt(int $workspaceId): CarbonInterface
    {
        $businessHours = WorkspaceBusinessHours::forWorkspace($workspaceId);
        $moment = now()->startOfMinute();
        $deadline = $moment->copy()->addDays(7);

        while ($moment->lt($deadline)) {
            if ($businessHours->isOpenAt($moment)) {
                return $moment;
            }

            $moment = $moment->addMinutes(15);
        }

        return now()->addHours(24);
    }

    private function recordSent(SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        SequenceEvent::create([
            'enrollment_id' => $enrollment->id,
            'step_position' => $step->position,
            'type' => SequenceEvent::TYPE_SENT,
            'created_at' => now(),
        ]);
    }

    private function advance(SequenceEnrollment $enrollment, SequenceStep $step): void
    {
        $next = $enrollment->sequence
            ?->steps()
            ->where('position', '>', $step->position)
            ->orderBy('position')
            ->first();

        $enrollment->update([
            'current_step' => $step->position,
            'next_run_at' => $next
                ? now()->addHours(max(0, (int) $next->delay_hours))
                : null,
        ]);
    }
}
