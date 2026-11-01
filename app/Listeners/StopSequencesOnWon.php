<?php

namespace App\Listeners;

use App\Events\LeadStageChanged;
use App\Modules\Sales\Services\SequenceEngine;

/** Stops active sequences when the lead is won or lost. */
class StopSequencesOnWon
{
    public function __construct(private readonly SequenceEngine $engine) {}

    public function handle(LeadStageChanged $event): void
    {
        if (! $event->isWon && ! $event->isLost) {
            return;
        }

        $this->engine->stopForLead(
            $event->workspaceId,
            $event->leadId,
            $event->isWon ? 'won' : 'lost',
            'stop_on_won',
        );
    }
}
