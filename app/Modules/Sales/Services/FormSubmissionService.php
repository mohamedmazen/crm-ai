<?php

namespace App\Modules\Sales\Services;

use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Services\LeadAssignmentService;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Sales\Models\Form;
use App\Modules\Sales\Models\FormField;
use App\Modules\Sales\Models\FormSubmission;
use App\Modules\Shared\Services\CustomFieldService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class FormSubmissionService
{
    public function __construct(
        private readonly PipelineManager $pipelineManager,
        private readonly LeadAssignmentService $assignmentService,
        private readonly CustomFieldService $customFieldService,
    ) {}

    /**
     * Process and record a public form submission.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function processSubmission(Form $form, array $payload, array $meta = []): array
    {
        $fields = $form->fields;

        // 1. Validate required fields
        $errors = [];
        foreach ($fields as $field) {
            $val = $payload[$field->field_key] ?? null;

            if ($field->required && ($val === null || $val === '')) {
                $errors[$field->field_key] = __(':field is required.', ['field' => $field->label]);
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($form, $fields, $payload, $meta) {
            // 2. Map payload into Lead attributes
            $leadData = [
                'workspace_id' => $form->workspace_id,
                'status' => 'new',
            ];

            $customFieldValues = [];

            foreach ($fields as $field) {
                $val = $payload[$field->field_key] ?? null;
                if ($val === null || $val === '') {
                    continue;
                }

                if (! empty($field->maps_to)) {
                    if (str_starts_with($field->maps_to, 'custom_field:')) {
                        $customKey = Str::after($field->maps_to, 'custom_field:');
                        $customFieldValues[$customKey] = $val;
                    } elseif (in_array($field->maps_to, ['name', 'phone', 'email', 'website', 'address', 'deal_value'], true)) {
                        $leadData[$field->maps_to] = $val;
                    }
                }
            }

            // Fallback for Name if not mapped
            if (empty($leadData['name'])) {
                if (! empty($leadData['email'])) {
                    $leadData['name'] = Str::headline(Str::before($leadData['email'], '@'));
                } elseif (! empty($leadData['phone'])) {
                    $leadData['name'] = 'Lead ' . $leadData['phone'];
                } else {
                    $leadData['name'] = 'Web Lead #' . rand(1000, 9999);
                }
            }

            // 3. Determine Pipeline and Target Stage
            $pipelineId = $form->pipeline_id;
            if (! $pipelineId) {
                $defaultPipe = $this->pipelineManager->defaultPipelineFor($form->workspace_id);
                $pipelineId = $defaultPipe->id;
            }

            $stageId = $form->target_stage_id;
            if (! $stageId) {
                $firstStage = $this->pipelineManager->firstStage($form->workspace_id, $pipelineId);
                $stageId = $firstStage->id;
            }

            $leadData['pipeline_id'] = $pipelineId;
            $leadData['stage_id'] = $stageId;

            // 4. Create the Lead
            $lead = Lead::create($leadData);

            // 5. Handle Assignment
            if ($form->assignment_mode === Form::ASSIGNMENT_SPECIFIC && $form->assigned_user_id) {
                $specificUser = User::find($form->assigned_user_id);
                if ($specificUser) {
                    $this->assignmentService->assign($lead, $specificUser);
                }
            } elseif ($form->assignment_mode === Form::ASSIGNMENT_ROUND_ROBIN) {
                $this->assignmentService->autoAssign($lead);
            }

            // 6. Handle Auto Tags
            if (! empty($form->auto_tags)) {
                $tagIds = [];
                foreach ($form->auto_tags as $tagIdentifier) {
                    if (is_numeric($tagIdentifier)) {
                        $tagIds[] = (int) $tagIdentifier;
                    } elseif (is_string($tagIdentifier) && trim($tagIdentifier) !== '') {
                        $tag = LeadTag::firstOrCreate(
                            ['workspace_id' => $form->workspace_id, 'name' => trim($tagIdentifier)],
                            ['color' => '#6366f1']
                        );
                        $tagIds[] = $tag->id;
                    }
                }

                if (! empty($tagIds)) {
                    $lead->tags()->syncWithoutDetaching($tagIds);
                }
            }

            // 7. Save Custom Field Values
            if (! empty($customFieldValues)) {
                $this->customFieldService->syncValues($form->workspace_id, 'lead', $lead->id, $customFieldValues);
            }

            // 8. Create Form Submission record
            $submission = FormSubmission::create([
                'form_id' => $form->id,
                'lead_id' => $lead->id,
                'payload' => $payload,
                'ip' => $meta['ip'] ?? null,
                'utm_source' => $meta['utm_source'] ?? $payload['utm_source'] ?? null,
                'utm_medium' => $meta['utm_medium'] ?? $payload['utm_medium'] ?? null,
                'utm_campaign' => $meta['utm_campaign'] ?? $payload['utm_campaign'] ?? null,
                'utm_term' => $meta['utm_term'] ?? $payload['utm_term'] ?? null,
                'utm_content' => $meta['utm_content'] ?? $payload['utm_content'] ?? null,
                'referrer' => $meta['referrer'] ?? null,
            ]);

            // 9. Increment form submissions counter
            $form->increment('submissions_count');

            // 10. Log Activity on the Lead
            LeadActivity::create([
                'workspace_id' => $form->workspace_id,
                'lead_id' => $lead->id,
                'type' => 'note',
                'body' => __('Lead captured from public form ":form".', ['form' => $form->name]),
                'meta' => [
                    'source' => 'form',
                    'form_id' => $form->id,
                    'form_name' => $form->name,
                    'submission_id' => $submission->id,
                ],
                'occurred_at' => now(),
            ]);

            return [
                'lead' => $lead,
                'submission' => $submission,
                'thank_you_type' => $form->thank_you_type,
                'thank_you_value' => $form->thank_you_value ?: __('Thank you for contacting us! We have received your details.'),
            ];
        });
    }
}

