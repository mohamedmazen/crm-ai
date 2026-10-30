<?php

namespace App\Modules\Shared\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Shared\Models\CustomField;
use App\Modules\Shared\Services\CustomFieldService;
use App\Services\FeatureGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CustomFieldController extends Controller
{
    public function __construct(
        private readonly CustomFieldService $fieldService,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $user = $request->user();

        $entityType = $request->input('entity_type', 'lead');
        if (! in_array($entityType, CustomField::ENTITY_TYPES, true)) {
            $entityType = 'lead';
        }

        $fields = $this->fieldService->fieldsFor($wid, $entityType);
        $totalCount = CustomField::where('workspace_id', $wid)->count();
        $limit = FeatureGate::forUser($user)->limit('custom_fields');

        return Inertia::render('CustomFields/Index', [
            'fields' => $fields,
            'currentEntityType' => $entityType,
            'entityTypes' => CustomField::ENTITY_TYPES,
            'availableTypes' => CustomField::TYPES,
            'usage' => [
                'current' => $totalCount,
                'limit' => $limit,
                'unlimited' => $limit === null,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $user = $request->user();

        $this->fieldService->assertWithinPlanLimit($user, $wid);

        $data = $request->validate([
            'entity_type' => ['required', Rule::in(CustomField::ENTITY_TYPES)],
            'label' => ['required', 'string', 'max:128'],
            'key' => [
                'nullable',
                'string',
                'max:64',
                'alpha_dash',
                Rule::unique('custom_fields')->where(fn ($q) => $q->where('workspace_id', $wid)->where('entity_type', $request->input('entity_type'))),
            ],
            'type' => ['required', Rule::in(CustomField::TYPES)],
            'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['string', 'max:128'],
            'required' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $key = ! empty($data['key']) ? Str::slug($data['key'], '_') : Str::slug($data['label'], '_');

        // Ensure unique fallback
        $existing = CustomField::where('workspace_id', $wid)
            ->where('entity_type', $data['entity_type'])
            ->where('key', $key)
            ->exists();

        if ($existing) {
            $key = $key.'_'.uniqid();
        }

        CustomField::create([
            'workspace_id' => $wid,
            'entity_type' => $data['entity_type'],
            'key' => $key,
            'label' => $data['label'],
            'type' => $data['type'],
            'options' => in_array($data['type'], ['select', 'multiselect'], true) ? array_values(array_filter(array_map('trim', $data['options'] ?? []))) : null,
            'required' => $data['required'] ?? false,
            'sort_order' => $data['sort_order'] ?? (CustomField::where('workspace_id', $wid)->where('entity_type', $data['entity_type'])->max('sort_order') + 1),
        ]);

        return back()->with('success', __('Custom field created.'));
    }

    public function update(Request $request, CustomField $customField): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $customField->workspace_id === $wid, 403);

        $data = $request->validate([
            'label' => ['required', 'string', 'max:128'],
            'options' => ['nullable', 'array', 'max:50'],
            'options.*' => ['string', 'max:128'],
            'required' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $customField->update([
            'label' => $data['label'],
            'options' => in_array($customField->type, ['select', 'multiselect'], true) ? array_values(array_filter(array_map('trim', $data['options'] ?? []))) : null,
            'required' => $data['required'] ?? $customField->required,
            'sort_order' => $data['sort_order'] ?? $customField->sort_order,
        ]);

        return back()->with('success', __('Custom field updated.'));
    }

    public function destroy(Request $request, CustomField $customField): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $customField->workspace_id === $wid, 403);

        $customField->delete();

        return back()->with('success', __('Custom field deleted.'));
    }

    public function saveValues(Request $request, string $entityType, int $entityId): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless(in_array($entityType, CustomField::ENTITY_TYPES, true), 400);

        $values = $request->input('values', []);
        $this->fieldService->syncValues($wid, $entityType, $entityId, $values);

        return back()->with('success', __('Custom fields saved.'));
    }
}

