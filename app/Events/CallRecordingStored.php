<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when a provider publishes a recording for a logged call. */
class CallRecordingStored
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $callId,
        public readonly ?int $leadId,
        public readonly string $recordingUrl,
    ) {}
}
