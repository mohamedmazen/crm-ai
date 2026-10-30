<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Models\LeadAssignmentConfig;
use App\Modules\Leads\Models\LeadCloseReason;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class PipelineSettingsController extends Controller
{
    public function __construct(
        private readonly PipelineManager $pipelineManager,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $pipelines = $this->pipelineManager->pipelinesFor($wid);
        $closeReasons = LeadCloseReason::where('workspace_id', $wid)
            ->orderBy('type')
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Leads/PipelineSettings', [
            'assignmentModes' => LeadAssignmentConfig::MODES,
            'pipelines' => $pipelines->map(fn (LeadPipeline $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'is_default' => (bool) $p->is_default,
                'sort_order' => $p->sort_order,
                'assignment_mode' => $p->assignmentConfig?->mode ?? LeadAssignmentConfig::MODE_MANUAL,
                'stages_count' => $p->stages()->count(),
                'leads_count' => $p->leads()->count(),
                'stages' => $p->stages->map(fn ($s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'color' => $s->color,
                    'position' => $s->position,
                    'is_won' => (bool) $s->is_won,
                    'is_lost' => (bool) $s->is_lost,
                    'default_probability' => $s->default_probability,
                ]),
            ]),
            'closeReasons' => $closeReasons->map(fn (LeadCloseReason $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'name' => $r->name,
                'sort_order' => $r->sort_order,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'is_default' => ['boolean'],
            'assignment_mode' => ['nullable', Rule::in(LeadAssignmentConfig::MODES)],
        ]);

        $sortOrder = (int) LeadPipeline::where('workspace_id', $wid)->max('sort_order') + 1;

        if (! empty($data['is_default'])) {
            LeadPipeline::where('workspace_id', $wid)->update(['is_default' => false]);
        }

        $pipeline = LeadPipeline::create([
            'workspace_id' => $wid,
            'name' => $data['name'],
            'is_default' => $data['is_default'] ?? false,
            'sort_order' => $sortOrder,
        ]);

        if (! empty($data['assignment_mode'])) {
            LeadAssignmentConfig::create([
                'pipeline_id' => $pipeline->id,
                'mode' => $data['assignment_mode'],
            ]);
        }

        // Seed default stages for the new pipeline
        $this->pipelineManager->stagesFor($wid, $pipeline->id);

        return back()->with('success', __('Pipeline created successfully.'));
    }

    public function update(Request $request, int $pipelineId): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $pipeline = LeadPipeline::where('workspace_id', $wid)->findOrFail($pipelineId);

        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:128'],
            'is_default' => ['boolean'],
            'assignment_mode' => ['nullable', Rule::in(LeadAssignmentConfig::MODES)],
        ]);

        if (! empty($data['is_default'])) {
            LeadPipeline::where('workspace_id', $wid)
                ->where('id', '!=', $pipeline->id)
                ->update(['is_default' => false]);
        }

        $pipelineUpdate = array_filter([
            'name' => $data['name'] ?? null,
            'is_default' => $data['is_default'] ?? null,
        ], fn ($val) => $val !== null);

        if (! empty($pipelineUpdate)) {
            $pipeline->update($pipelineUpdate);
        }

        if (array_key_exists('assignment_mode', $data)) {
            LeadAssignmentConfig::updateOrCreate(
                ['pipeline_id' => $pipeline->id],
                ['mode' => $data['assignment_mode'] ?? LeadAssignmentConfig::MODE_MANUAL]
            );
        }

        return back()->with('success', __('Pipeline updated.'));
    }

    public function destroy(Request $request, int $pipelineId): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $pipeline = LeadPipeline::where('workspace_id', $wid)->findOrFail($pipelineId);

        $count = LeadPipeline::where('workspace_id', $wid)->count();
        if ($count <= 1) {
            throw ValidationException::withMessages([
                'pipeline' => __('You cannot delete your only pipeline.'),
            ]);
        }

        // Find fallback pipeline
        $fallback = LeadPipeline::where('workspace_id', $wid)
            ->where('id', '!=', $pipeline->id)
            ->first();

        // Move leads to fallback pipeline's first stage
        $firstStage = $fallback->stages()->first();
        if ($firstStage) {
            $pipeline->leads()->update([
                'pipeline_id' => $fallback->id,
                'stage_id' => $firstStage->id,
            ]);
        }

        $pipeline->delete();

        return back()->with('success', __('Pipeline deleted.'));
    }

    public function storeReason(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $request->validate([
            'type' => ['required', Rule::in([LeadCloseReason::TYPE_WON, LeadCloseReason::TYPE_LOST])],
            'name' => ['required', 'string', 'max:128'],
        ]);

        $sortOrder = (int) LeadCloseReason::where('workspace_id', $wid)
            ->where('type', $data['type'])
            ->max('sort_order') + 1;

        LeadCloseReason::create([
            'workspace_id' => $wid,
            'type' => $data['type'],
            'name' => $data['name'],
            'sort_order' => $sortOrder,
        ]);

        return back()->with('success', __('Reason added.'));
    }

    public function destroyReason(Request $request, int $reasonId): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $reason = LeadCloseReason::where('workspace_id', $wid)->findOrFail($reasonId);

        $reason->delete();

        return back()->with('success', __('Reason deleted.'));
    }
}

