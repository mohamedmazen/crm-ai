<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired when a lead is moved into a stage that belongs to a different
 * pipeline. Like LeadStageChanged, contactId is null until the lead has been
 * pushed to contacts, because automations act on a contact.
 */
class LeadPipelineChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $leadId,
        public readonly ?int $contactId,
        public readonly ?string $fromPipeline,
        public readonly string $toPipeline,
    ) {}

    /** @return array<string, string> flat map consumed by {{context.x}} message tokens */
    public function context(): array
    {
        return [
            'lead_id' => (string) $this->leadId,
            'lead_pipeline_from' => (string) ($this->fromPipeline ?? ''),
            'lead_pipeline_to' => $this->toPipeline,
        ];
    }
}
