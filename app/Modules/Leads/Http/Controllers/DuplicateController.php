<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Models\LeadDuplicateGroup;
use App\Modules\Leads\Services\DuplicateDetector;
use App\Modules\Leads\Services\LeadMergeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DuplicateController extends Controller
{
    public function __construct(
        private readonly DuplicateDetector $detector,
        private readonly LeadMergeService $mergeService,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $groups = LeadDuplicateGroup::where('workspace_id', $wid)
            ->pending()
            ->with([
                'leads' => function ($q) {
                    $q->with([
                        'stage:id,name',
                        'pipeline:id,name',
                        'company:id,name',
                        'assignedUser:id,name',
                        'tags:id,name,color',
                    ])
                    ->withCount(['activities', 'tasks']);
                },
            ])
            ->latest()
            ->paginate(15);

        $pendingCount = LeadDuplicateGroup::where('workspace_id', $wid)->pending()->count();

        return Inertia::render('Leads/Duplicates/Index', [
            'groups' => $groups,
            'pendingCount' => $pendingCount,
        ]);
    }

    public function scan(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $found = $this->detector->scanWorkspace($wid);

        return back()->with(
            'success',
            __('Scan complete. Found :count duplicate group(s).', ['count' => $found])
        );
    }

    public function dismiss(Request $request, LeadDuplicateGroup $group): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $group->workspace_id === $wid, 403);

        $group->update(['status' => LeadDuplicateGroup::STATUS_DISMISSED]);

        return back()->with('success', __('Duplicate group dismissed.'));
    }

    public function merge(Request $request, LeadDuplicateGroup $group): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $group->workspace_id === $wid, 403);

        $data = $request->validate([
            'primary_lead_id' => ['required', 'integer'],
            'secondary_lead_ids' => ['required', 'array', 'min:1'],
            'secondary_lead_ids.*' => ['integer'],
            'overrides' => ['nullable', 'array'],
        ]);

        $this->mergeService->merge(
            $wid,
            $data['primary_lead_id'],
            $data['secondary_lead_ids'],
            $data['overrides'] ?? [],
            $group->id,
            $request->user()
        );

        return back()->with('success', __('Leads merged successfully.'));
    }
}

