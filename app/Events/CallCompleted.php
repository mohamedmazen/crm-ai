<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired once a call reaches a terminal status (completed, failed, no answer). */
class CallCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $callId,
        public readonly ?int $leadId,
        public readonly string $status,
        public readonly int $durationSecs = 0,
    ) {}
}
