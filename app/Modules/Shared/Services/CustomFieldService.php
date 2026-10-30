<?php

namespace App\Modules\Shared\Services;

use App\Modules\Shared\Models\CustomField;
use App\Modules\Shared\Models\CustomFieldValue;
use App\Services\FeatureGate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomFieldService
{
    /**
     * Get all custom fields defined for an entity type in a workspace.
     *
     * @return Collection<int, CustomField>
     */
    public function fieldsFor(int $workspaceId, string $entityType): Collection
    {
        return CustomField::where('workspace_id', $workspaceId)
            ->forEntity($entityType)
            ->ordered()
            ->get();
    }

    /**
     * Get associative key => value mapping of custom fields for an entity.
     *
     * @return array<string, mixed>
     */
    public function valuesFor(int $workspaceId, string $entityType, int $entityId): array
    {
        $fields = $this->fieldsFor($workspaceId, $entityType)->keyBy('id');

        $storedValues = CustomFieldValue::where('workspace_id', $workspaceId)
            ->where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->get();

        $result = [];

        foreach ($storedValues as $val) {
            $field = $fields->get($val->field_id);
            if (! $field) {
                continue;
            }

            $raw = $val->value;
            $parsed = match ($field->type) {
                'number' => is_numeric($raw) ? (float) $raw : null,
                'checkbox' => filter_var($raw, FILTER_VALIDATE_BOOLEAN),
                'multiselect' => json_decode((string) $raw, true) ?: [],
                default => $raw,
            };

            $result[$field->key] = $parsed;
        }

        return $result;
    }

    /**
     * Format custom fields with their definitions and current values for frontend forms.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fieldsWithValues(int $workspaceId, string $entityType, int $entityId): array
    {
        $fields = $this->fieldsFor($workspaceId, $entityType);
        $values = $this->valuesFor($workspaceId, $entityType, $entityId);

        return $fields->map(fn (CustomField $f) => [
            'id' => $f->id,
            'key' => $f->key,
            'label' => $f->label,
            'type' => $f->type,
            'options' => $f->options ?? [],
            'required' => (bool) $f->required,
            'value' => $values[$f->key] ?? ($f->type === 'checkbox' ? false : ($f->type === 'multiselect' ? [] : '')),
        ])->values()->all();
    }

    /**
     * Save/sync custom field values for an entity.
     *
     * @param  array<string, mixed>  $values
     */
    public function syncValues(int $workspaceId, string $entityType, int $entityId, array $values): void
    {
        $fields = $this->fieldsFor($workspaceId, $entityType);

        DB::transaction(function () use ($workspaceId, $entityType, $entityId, $values, $fields) {
            foreach ($fields as $field) {
                // Check if key is provided in values
                if (! array_key_exists($field->key, $values) && ! array_key_exists((string) $field->id, $values)) {
                    continue;
                }

                $raw = $values[$field->key] ?? $values[(string) $field->id];

                // If null or empty string, delete value or set to null
                if ($raw === null || $raw === '') {
                    CustomFieldValue::where('workspace_id', $workspaceId)
                        ->where('field_id', $field->id)
                        ->where('entity_type', $entityType)
                        ->where('entity_id', $entityId)
                        ->delete();

                    continue;
                }

                $serialized = match ($field->type) {
                    'checkbox' => $raw ? '1' : '0',
                    'multiselect' => is_array($raw) ? json_encode(array_values($raw)) : json_encode([$raw]),
                    default => (string) $raw,
                };

                CustomFieldValue::updateOrCreate(
                    [
                        'field_id' => $field->id,
                        'entity_type' => $entityType,
                        'entity_id' => $entityId,
                    ],
                    [
                        'workspace_id' => $workspaceId,
                        'value' => $serialized,
                    ]
                );
            }
        });
    }

    /**
     * Validate plan limit when creating a new custom field.
     */
    public function assertWithinPlanLimit(\App\Models\User $user, int $workspaceId): void
    {
        $limit = FeatureGate::forUser($user)->limit('custom_fields');

        if ($limit === null) {
            return; // Unlimited on this plan
        }

        $currentCount = CustomField::where('workspace_id', $workspaceId)->count();

        if ($currentCount >= $limit) {
            throw ValidationException::withMessages([
                'custom_fields' => __("Your current plan allows up to :limit custom field(s). Please upgrade to add more.", ['limit' => $limit]),
            ]);
        }
    }
}

