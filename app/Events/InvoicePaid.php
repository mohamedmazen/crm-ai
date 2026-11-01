<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Fired when recorded payments cover an invoice's total. */
class InvoicePaid
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $invoiceId,
        public readonly ?int $leadId,
        public readonly float $total = 0,
        public readonly ?string $currency = null,
    ) {}
}
