<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Services\LeadAssignmentService;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Sales\Models\Form;
use App\Modules\Sales\Models\FormField;
use App\Modules\Shared\Models\CustomField;
use App\Services\FeatureGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class FormController extends Controller
{
    public function __construct(
        private readonly PipelineManager $pipelineManager,
        private readonly LeadAssignmentService $assignmentService,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $user = $request->user();

        $forms = Form::where('workspace_id', $wid)
            ->with(['pipeline:id,name', 'stage:id,name'])
            ->withCount('submissions')
            ->latest()
            ->paginate(15);

        $totalCount = Form::where('workspace_id', $wid)->count();
        $limit = FeatureGate::forUser($user)->limit('forms');

        $items = collect($forms->items())->map(function (Form $f) {
            $arr = $f->toArray();
            $arr['conversion_rate'] = $f->conversionRate();
            $arr['public_url'] = $f->publicUrl();
            $arr['embed_code'] = $f->embedCode();

            return $arr;
        });

        $paginated = $forms->setCollection($items);

        $pipelines = LeadPipeline::where('workspace_id', $wid)
            ->with('stages')
            ->get();

        $teamMembers = $this->assignmentService->eligibleUsersFor($wid)->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ])->values();

        return Inertia::render('Forms/Index', [
            'forms' => $paginated,
            'pipelines' => $pipelines,
            'teamMembers' => $teamMembers,
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

        // 1. Check feature limit
        $limit = FeatureGate::forUser($user)->limit('forms');
        if ($limit !== null) {
            $current = Form::where('workspace_id', $wid)->count();
            if ($current >= $limit) {
                throw ValidationException::withMessages([
                    'forms' => __('Your plan allows up to :limit form(s). Please upgrade to create more.', ['limit' => $limit]),
                ]);
            }
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'pipeline_id' => ['nullable', 'integer'],
            'target_stage_id' => ['nullable', 'integer'],
            'assignment_mode' => ['nullable', Rule::in(['none', 'specific', 'round_robin'])],
            'assigned_user_id' => ['nullable', 'integer'],
        ]);

        $defaultPipeline = $this->pipelineManager->defaultPipelineFor($wid);
        $defaultStage = $this->pipelineManager->firstStage($wid, $defaultPipeline->id);

        $slug = Str::slug($data['name']);
        if (Form::where('workspace_id', $wid)->where('slug', $slug)->exists()) {
            $slug = $slug.'-'.Str::random(5);
        }

        $form = Form::create([
            'workspace_id' => $wid,
            'name' => $data['name'],
            'slug' => $slug,
            'status' => Form::STATUS_PUBLISHED,
            'pipeline_id' => $data['pipeline_id'] ?? $defaultPipeline->id,
            'target_stage_id' => $data['target_stage_id'] ?? $defaultStage->id,
            'assignment_mode' => $data['assignment_mode'] ?? 'none',
            'assigned_user_id' => $data['assigned_user_id'] ?? null,
            'thank_you_type' => 'message',
            'thank_you_value' => __('Thank you! Your submission has been received.'),
            'settings' => [
                'primary_color' => '#6366f1',
                'button_text' => 'Submit',
                'show_title' => true,
            ],
        ]);

        // Create standard default starter fields
        $defaultFields = [
            ['type' => 'text', 'label' => 'Full Name', 'field_key' => 'name', 'placeholder' => 'John Doe', 'required' => true, 'maps_to' => 'name', 'sort_order' => 1],
            ['type' => 'email', 'label' => 'Email Address', 'field_key' => 'email', 'placeholder' => 'john@example.com', 'required' => true, 'maps_to' => 'email', 'sort_order' => 2],
            ['type' => 'phone', 'label' => 'Phone Number', 'field_key' => 'phone', 'placeholder' => '+1 (555) 000-0000', 'required' => false, 'maps_to' => 'phone', 'sort_order' => 3],
            ['type' => 'textarea', 'label' => 'How can we help?', 'field_key' => 'message', 'placeholder' => 'Tell us about your project...', 'required' => false, 'maps_to' => null, 'sort_order' => 4],
        ];

        foreach ($defaultFields as $f) {
            $form->fields()->create($f);
        }

        return redirect()->route('client.sales.forms.builder', $form->id)->with('success', __('Form created successfully.'));
    }

    public function builder(Request $request, Form $form): Response
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $form->workspace_id === $wid, 403);

        $form->load(['fields']);

        $pipelines = LeadPipeline::where('workspace_id', $wid)
            ->with('stages')
            ->get();

        $teamMembers = $this->assignmentService->eligibleUsersFor($wid)->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ])->values();

        $tags = LeadTag::where('workspace_id', $wid)->get(['id', 'name', 'color']);

        $customFields = CustomField::where('workspace_id', $wid)
            ->where('entity_type', 'lead')
            ->get(['id', 'key', 'label', 'type']);

        return Inertia::render('Forms/Builder', [
            'form' => [
                ...$form->toArray(),
                'public_url' => $form->publicUrl(),
                'embed_code' => $form->embedCode(),
            ],
            'fields' => $form->fields,
            'pipelines' => $pipelines,
            'teamMembers' => $teamMembers,
            'tags' => $tags,
            'customFields' => $customFields,
            'availableFieldTypes' => FormField::TYPES,
            'mapsToOptions' => FormField::MAPS_TO_FIELDS,
        ]);
    }

    public function updateBuilder(Request $request, Form $form): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $form->workspace_id === $wid, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'status' => ['required', Rule::in(['draft', 'published', 'archived'])],
            'pipeline_id' => ['nullable', 'integer'],
            'target_stage_id' => ['nullable', 'integer'],
            'assignment_mode' => ['required', Rule::in(['none', 'specific', 'round_robin'])],
            'assigned_user_id' => ['nullable', 'integer'],
            'auto_tags' => ['nullable', 'array'],
            'thank_you_type' => ['required', Rule::in(['message', 'redirect'])],
            'thank_you_value' => ['nullable', 'string'],
            'settings' => ['nullable', 'array'],
            'fields' => ['required', 'array', 'min:1'],
            'fields.*.id' => ['nullable', 'integer'],
            'fields.*.type' => ['required', Rule::in(array_keys(FormField::TYPES))],
            'fields.*.label' => ['required', 'string', 'max:256'],
            'fields.*.field_key' => ['required', 'string', 'max:64'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:256'],
            'fields.*.help_text' => ['nullable', 'string', 'max:256'],
            'fields.*.required' => ['boolean'],
            'fields.*.options' => ['nullable', 'array'],
            'fields.*.maps_to' => ['nullable', 'string', 'max:64'],
            'fields.*.sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $form->update([
            'name' => $data['name'],
            'status' => $data['status'],
            'pipeline_id' => $data['pipeline_id'] ?? $form->pipeline_id,
            'target_stage_id' => $data['target_stage_id'] ?? $form->target_stage_id,
            'assignment_mode' => $data['assignment_mode'],
            'assigned_user_id' => $data['assigned_user_id'],
            'auto_tags' => $data['auto_tags'] ?? [],
            'thank_you_type' => $data['thank_you_type'],
            'thank_you_value' => $data['thank_you_value'],
            'settings' => $data['settings'] ?? $form->settings,
        ]);

        // Sync fields
        $existingFieldIds = [];

        foreach ($data['fields'] as $index => $fieldData) {
            $fieldId = $fieldData['id'] ?? null;

            $attrs = [
                'type' => $fieldData['type'],
                'label' => $fieldData['label'],
                'field_key' => Str::slug($fieldData['field_key'], '_'),
                'placeholder' => $fieldData['placeholder'] ?? null,
                'help_text' => $fieldData['help_text'] ?? null,
                'required' => $fieldData['required'] ?? false,
                'options' => ! empty($fieldData['options']) ? array_values(array_filter($fieldData['options'])) : null,
                'maps_to' => $fieldData['maps_to'] ?? null,
                'sort_order' => $fieldData['sort_order'] ?? ($index + 1),
            ];

            if ($fieldId) {
                $f = FormField::where('form_id', $form->id)->find($fieldId);
                if ($f) {
                    $f->update($attrs);
                    $existingFieldIds[] = $f->id;

                    continue;
                }
            }

            $newField = $form->fields()->create($attrs);
            $existingFieldIds[] = $newField->id;
        }

        // Delete removed fields
        FormField::where('form_id', $form->id)
            ->whereNotIn('id', $existingFieldIds)
            ->delete();

        return back()->with('success', __('Form saved successfully.'));
    }

    public function analytics(Request $request, Form $form): Response
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $form->workspace_id === $wid, 403);

        $submissions = $form->submissions()
            ->with(['lead:id,name,phone,email,status,deal_value,stage_id'])
            ->latest()
            ->paginate(20);

        // UTM breakdown
        $utmSources = $form->submissions()
            ->whereNotNull('utm_source')
            ->selectRaw('utm_source, COUNT(*) as count')
            ->groupBy('utm_source')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return Inertia::render('Forms/Analytics', [
            'form' => [
                ...$form->toArray(),
                'conversion_rate' => $form->conversionRate(),
                'public_url' => $form->publicUrl(),
            ],
            'submissions' => $submissions,
            'utmSources' => $utmSources,
        ]);
    }

    public function destroy(Request $request, Form $form): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $form->workspace_id === $wid, 403);

        $form->delete();

        return redirect()->route('client.sales.forms.index')->with('success', __('Form deleted successfully.'));
    }
}

