<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when a quote is emailed to its customer. */
class QuoteSent
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
