<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired after a single sequence step has been handed to the provider. */
class SequenceStepSent
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $sequenceId,
        public readonly int $enrollmentId,
        public readonly int $leadId,
        public readonly int $position,
        public readonly string $channel,
        public readonly ?string $messageId = null,
    ) {}
}
