<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a rescore actually moves a lead's score. Unlike LeadQualified
 * (hot-band only), this carries the previous value so a `lead.score_threshold`
 * automation can fire on the upward crossing of its own configured threshold
 * without re-announcing an already-above-threshold lead on every engagement.
 *
 * contactId is null until the lead is pushed to contacts; see LeadStageChanged.
 */
class LeadScoreChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $leadId,
        public readonly ?int $contactId,
        public readonly int $previousScore,
        public readonly int $score,
        public readonly string $band,
    ) {}

    /** @return array<string, string> flat map consumed by {{context.x}} message tokens */
    public function context(): array
    {
        return [
            'lead_id' => (string) $this->leadId,
            'lead_score' => (string) $this->score,
            'lead_previous_score' => (string) $this->previousScore,
            'lead_band' => $this->band,
        ];
    }
}
