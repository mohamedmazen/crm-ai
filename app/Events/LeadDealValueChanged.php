<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when the economics of a deal change. contactId is null until the lead
 * has been pushed to contacts.
 */
class LeadDealValueChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $leadId,
        public readonly ?int $contactId,
        public readonly ?float $fromValue,
        public readonly ?float $toValue,
        public readonly ?string $currency = null,
    ) {}

    /** @return array<string, string> flat map consumed by {{context.x}} message tokens */
    public function context(): array
    {
        return [
            'lead_id' => (string) $this->leadId,
            'deal_value_from' => $this->formatValue($this->fromValue),
            'deal_value_to' => $this->formatValue($this->toValue),
            'deal_currency' => (string) ($this->currency ?? ''),
        ];
    }

    private function formatValue(?float $value): string
    {
        return $value === null ? '' : number_format($value, 2, '.', '');
    }
}
