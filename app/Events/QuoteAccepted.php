<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when the customer accepts a quote, publicly or from the app. */
class QuoteAccepted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $quoteId,
        public readonly ?int $leadId,
        public readonly float $total = 0,
        public readonly ?string $currency = null,
    ) {}
}
