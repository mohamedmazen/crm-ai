<?php

namespace App\Modules\Leads\Services;

use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Models\LeadDuplicateGroup;
use App\Modules\Leads\Models\LeadStageHistory;
use App\Modules\Leads\Models\LeadTask;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\CustomFieldValue;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LeadMergeService
{
    /**
     * Merge one or more secondary leads into a primary lead.
     *
     * @param  array<int>  $secondaryLeadIds
     * @param  array<string, mixed>  $fieldOverrides
     */
    public function merge(
        int $workspaceId,
        int $primaryLeadId,
        array $secondaryLeadIds,
        array $fieldOverrides = [],
        ?int $groupId = null,
        ?User $actor = null
    ): Lead {
        if (in_array($primaryLeadId, $secondaryLeadIds, true)) {
            throw new InvalidArgumentException(__('Primary lead cannot be included in secondary leads to merge.'));
        }

        return DB::transaction(function () use (
            $workspaceId,
            $primaryLeadId,
            $secondaryLeadIds,
            $fieldOverrides,
            $groupId,
            $actor
        ) {
            $primaryLead = Lead::where('workspace_id', $workspaceId)->lockForUpdate()->findOrFail($primaryLeadId);

            $secondaryLeads = Lead::where('workspace_id', $workspaceId)
                ->whereIn('id', $secondaryLeadIds)
                ->lockForUpdate()
                ->get();

            if ($secondaryLeads->isEmpty()) {
                throw new InvalidArgumentException(__('No valid secondary leads found to merge.'));
            }

            foreach ($secondaryLeads as $secondary) {
                // 1. Move Activities
                LeadActivity::where('lead_id', $secondary->id)
                    ->update(['lead_id' => $primaryLead->id]);

                // 2. Move Tasks
                LeadTask::where('lead_id', $secondary->id)
                    ->update(['lead_id' => $primaryLead->id]);

                // 3. Move Stage History
                LeadStageHistory::where('lead_id', $secondary->id)
                    ->update(['lead_id' => $primaryLead->id]);

                // 4. Repoint contacts that were linked to the secondary lead.
                // contacts.lead_id has no foreign key, so deleting the secondary
                // would otherwise leave contacts pointing at a row that is gone.
                Contact::where('workspace_id', $workspaceId)
                    ->where('lead_id', $secondary->id)
                    ->update(['lead_id' => $primaryLead->id]);

                // 5. Merge Tags
                $tagIds = DB::table('lead_tag_pivot')
                    ->where('lead_id', $secondary->id)
                    ->pluck('tag_id')
                    ->all();

                if (! empty($tagIds)) {
                    $primaryLead->tags()->syncWithoutDetaching($tagIds);
                }

                // 6. Merge Custom Field Values
                $existingFieldIds = CustomFieldValue::where('workspace_id', $workspaceId)
                    ->where('entity_type', 'lead')
                    ->where('entity_id', $primaryLead->id)
                    ->pluck('field_id')
                    ->all();

                CustomFieldValue::where('workspace_id', $workspaceId)
                    ->where('entity_type', 'lead')
                    ->where('entity_id', $secondary->id)
                    ->whereNotIn('field_id', $existingFieldIds)
                    ->update(['entity_id' => $primaryLead->id]);

                // Delete any remaining duplicate custom field values for secondary
                CustomFieldValue::where('workspace_id', $workspaceId)
                    ->where('entity_type', 'lead')
                    ->where('entity_id', $secondary->id)
                    ->delete();

                // 7. Fill in blanks on primary if not overridden
                $fillableColumns = ['phone', 'email', 'website', 'address', 'city', 'country', 'company_id', 'deal_value'];
                foreach ($fillableColumns as $col) {
                    if (empty($primaryLead->{$col}) && ! empty($secondary->{$col})) {
                        $primaryLead->{$col} = $secondary->{$col};
                    }
                }

                // 8. Log merge activity
                LeadActivity::create([
                    'workspace_id' => $workspaceId,
                    'lead_id' => $primaryLead->id,
                    'user_id' => $actor?->id,
                    'type' => 'note',
                    'body' => __('Merged lead ":name" (#:id) into this record. All activities, tasks, and history were combined.', [
                        'name' => $secondary->name,
                        'id' => $secondary->id,
                    ]),
                    'meta' => [
                        'action' => 'merge',
                        'merged_from_id' => $secondary->id,
                        'merged_from_name' => $secondary->name,
                    ],
                    'occurred_at' => now(),
                ]);

                // 9. Delete secondary lead
                $secondary->delete();
            }

            // Apply manual field overrides if provided
            if (! empty($fieldOverrides)) {
                $allowedOverrides = [
                    'name', 'phone', 'email', 'website', 'address',
                    'company_id', 'deal_value', 'currency', 'probability',
                    'status', 'stage_id', 'pipeline_id', 'assigned_user_id',
                ];

                $filtered = array_intersect_key($fieldOverrides, array_flip($allowedOverrides));
                $primaryLead->fill($filtered);
            }

            $primaryLead->save();

            // 10. Update duplicate group if specified
            if ($groupId) {
                LeadDuplicateGroup::where('workspace_id', $workspaceId)
                    ->where('id', $groupId)
                    ->update(['status' => LeadDuplicateGroup::STATUS_MERGED]);
            }

            return $primaryLead;
        });
    }
}
