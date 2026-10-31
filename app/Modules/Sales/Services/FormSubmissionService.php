<?php

namespace App\Modules\Sales\Services;

use App\Events\FormSubmitted;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Services\LeadAssignmentService;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Sales\Exceptions\FormSubmissionBlockedException;
use App\Modules\Sales\Models\Form;
use App\Modules\Sales\Models\FormSubmission;
use App\Modules\Sales\Models\LeadSource;
use App\Modules\Shared\Models\CustomFieldValue;
use App\Modules\Shared\Services\CustomFieldService;
use App\Services\FeatureGate;
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
        $this->ensureWithinSubmissionCap($form);

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

        $result = DB::transaction(function () use ($form, $fields, $payload, $meta) {
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
                    $leadData['name'] = 'Lead '.$leadData['phone'];
                } else {
                    $leadData['name'] = 'Web Lead #'.rand(1000, 9999);
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

            // Attribute Lead Source and UTM parameters
            $formSource = LeadSource::firstOrCreate(
                ['workspace_id' => $form->workspace_id, 'type' => LeadSource::TYPE_FORM],
                ['name' => 'Web Forms', 'status' => LeadSource::STATUS_ACTIVE]
            );
            $formSource->update(['last_lead_at' => now()]);

            $leadData['source'] = 'Form: '.$form->name;
            $leadData['lead_source_id'] = $formSource->id;
            $leadData['utm_source'] = $meta['utm_source'] ?? null;
            $leadData['utm_medium'] = $meta['utm_medium'] ?? null;
            $leadData['utm_campaign'] = $meta['utm_campaign'] ?? null;
            $leadData['utm_term'] = $meta['utm_term'] ?? null;
            $leadData['utm_content'] = $meta['utm_content'] ?? null;
            $leadData['referrer'] = $meta['referrer'] ?? null;
            $leadData['landing_page'] = $meta['landing_page'] ?? null;

            // 4. Reuse the lead this person already has instead of duplicating them.
            $lead = $this->findExistingLead($form, $leadData);
            $isNewLead = $lead === null;

            if ($isNewLead) {
                $lead = Lead::create($leadData);
            } else {
                $this->fillMissingLeadFields($lead, $leadData);
            }

            // 5. Handle Assignment (never re-assign a lead that already has an owner)
            if ($isNewLead) {
                if ($form->assignment_mode === Form::ASSIGNMENT_SPECIFIC && $form->assigned_user_id) {
                    $specificUser = User::find($form->assigned_user_id);
                    if ($specificUser) {
                        $this->assignmentService->assign($lead, $specificUser);
                    }
                } elseif ($form->assignment_mode === Form::ASSIGNMENT_ROUND_ROBIN) {
                    $this->assignmentService->autoAssign($lead);
                }
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

            // 7. Save Custom Field Values. On a resubmission, only fill fields
            // that are still empty so a rep's manual edits are never clobbered.
            $customFieldValues = $isNewLead
                ? $customFieldValues
                : $this->dropFilledCustomFields($form->workspace_id, $lead->id, $customFieldValues);

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
                'lead_id' => $lead->id,
                'submission' => $submission,
                'thank_you_type' => $form->thank_you_type,
                'thank_you_value' => $form->thank_you_value ?: __('Thank you for contacting us! We have received your details.'),
            ];
        });

        FormSubmitted::dispatch(
            (int) $form->workspace_id,
            (int) $form->id,
            (int) $result['lead']->id,
            $result['lead']->contact?->id,
            $form->name,
            (int) $result['submission']->id,
        );

        return $result;
    }

    /**
     * Refuse the submission once the workspace has used up the monthly cap on
     * its plan. A null limit means unlimited; a limit of 0 blocks everything.
     */
    private function ensureWithinSubmissionCap(Form $form): void
    {
        $limit = FeatureGate::forWorkspace($form->workspace)->limit('form_submissions');

        if ($limit === null) {
            return;
        }

        $usedThisMonth = FormSubmission::query()
            ->whereHas('form', fn ($query) => $query->where('workspace_id', $form->workspace_id))
            ->where('created_at', '>=', now()->startOfMonth())
            ->count();

        if ($usedThisMonth >= $limit) {
            throw new FormSubmissionBlockedException(
                __('This workspace has reached its monthly form submission limit.'),
            );
        }
    }

    /**
     * Find the lead this submitter already has. Email wins over phone, and the
     * email comparison is case-insensitive because addresses are.
     *
     * @param  array<string, mixed>  $leadData
     */
    private function findExistingLead(Form $form, array $leadData): ?Lead
    {
        $email = $leadData['email'] ?? null;
        $phone = $leadData['phone'] ?? null;

        if (blank($email) && blank($phone)) {
            return null;
        }

        return Lead::where('workspace_id', $form->workspace_id)
            ->where(function ($query) use ($email, $phone) {
                if (! blank($email)) {
                    $query->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $email))]);
                }

                if (! blank($phone)) {
                    $method = blank($email) ? 'where' : 'orWhere';
                    $query->{$method}('phone', trim((string) $phone));
                }
            })
            ->orderBy('id')
            ->first();
    }

    /**
     * Only copy contact fields the lead does not have yet. Pipeline, stage,
     * status, source and UTM stay as they were on the original acquisition.
     *
     * @param  array<string, mixed>  $leadData
     */
    private function fillMissingLeadFields(Lead $lead, array $leadData): void
    {
        $dirty = false;

        foreach (['name', 'phone', 'email', 'website', 'address'] as $column) {
            if (blank($lead->{$column}) && ! blank($leadData[$column] ?? null)) {
                $lead->{$column} = $leadData[$column];
                $dirty = true;
            }
        }

        if ($dirty) {
            $lead->save();
        }
    }

    /**
     * Drop custom field values the lead already has so a resubmission cannot
     * overwrite data a salesperson edited by hand.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function dropFilledCustomFields(int $workspaceId, int $leadId, array $values): array
    {
        if ($values === []) {
            return [];
        }

        $filledKeys = CustomFieldValue::query()
            ->where('custom_field_values.workspace_id', $workspaceId)
            ->where('custom_field_values.entity_type', 'lead')
            ->where('custom_field_values.entity_id', $leadId)
            ->join('custom_fields', 'custom_fields.id', '=', 'custom_field_values.field_id')
            ->pluck('custom_fields.key')
            ->all();

        return array_diff_key($values, array_flip($filledKeys));
    }
}
