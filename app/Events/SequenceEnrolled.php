<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a lead is added to an email / WhatsApp sequence.
 *
 * Payload is scalar ids only so later phases (AI SDR) can listen without
 * coupling to the Sales module's Eloquent models.
 */
class SequenceEnrolled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $sequenceId,
        public readonly int $enrollmentId,
        public readonly int $leadId,
        public readonly string $source = 'manual',
    ) {}
}
