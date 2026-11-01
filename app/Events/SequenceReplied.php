<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a lead replies while enrolled in a sequence.
 *
 * This is the seam AI SDR (Phase 6) listens to: it needs the reply signal,
 * not the sequence send machinery.
 */
class SequenceReplied
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $sequenceId,
        public readonly int $enrollmentId,
        public readonly int $leadId,
        public readonly ?int $messageId = null,
        public readonly ?string $channel = null,
    ) {}
}
