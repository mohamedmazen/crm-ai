<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after a public form submission has been persisted. contactId is null
 * when the submission created a brand-new lead that has not been pushed to
 * contacts yet; automations need a contact to act on.
 */
class FormSubmitted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $formId,
        public readonly int $leadId,
        public readonly ?int $contactId,
        public readonly string $formName,
        public readonly int $submissionId,
    ) {}

    /** @return array<string, string> flat map consumed by {{context.x}} message tokens */
    public function context(): array
    {
        return [
            'lead_id' => (string) $this->leadId,
            'form_id' => (string) $this->formId,
            'form_name' => $this->formName,
            'submission_id' => (string) $this->submissionId,
        ];
    }
}
