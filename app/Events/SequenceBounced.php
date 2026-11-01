<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when a sequence send hard-bounces (invalid address, blocked number). */
class SequenceBounced
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $sequenceId,
        public readonly int $enrollmentId,
        public readonly int $leadId,
        public readonly string $reason,
    ) {}
}
