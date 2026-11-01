<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when an outbound call is accepted by the provider.
 *
 * Scalar ids only so later phases (AI call summaries) can listen without
 * depending on the Sales module's models.
 */
class CallInitiated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $callId,
        public readonly ?int $leadId,
        public readonly ?int $userId,
        public readonly string $direction = 'outbound',
        public readonly ?string $providerSid = null,
    ) {}
}
