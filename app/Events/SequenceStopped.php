<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when a sequence enrollment is stopped before it finishes. */
class SequenceStopped
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
