<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Services\LeadAssignmentService;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class LeadBulkActionController extends Controller
{
    public function __construct(
        private readonly LeadAssignmentService $assignment,
        private readonly PipelineManager $pipeline,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function assign(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'user_id' => ['nullable', 'integer'],
        ]);

        $user = ! empty($validated['user_id']) ? User::findOrFail($validated['user_id']) : null;

        if ($user) {
            $eligible = $this->assignment->eligibleUsersFor($wid);
            if (! $eligible->contains('id', $user->id)) {
                abort(403, 'User does not belong to this organization.');
            }
        }

        $leads = Lead::where('workspace_id', $wid)
            ->whereIn('id', $validated['ids'])
            ->get();

        foreach ($leads as $lead) {
            $this->assignment->assign($lead, $user, $request->user());
        }

        $msg = $user
            ? __(':count lead(s) assigned to :name.', ['count' => $leads->count(), 'name' => $user->name])
            : __(':count lead(s) unassigned.', ['count' => $leads->count()]);

        return back()->with('success', $msg);
    }

    public function moveStage(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'stage_id' => ['required', 'integer'],
        ]);

        $stage = LeadPipelineStage::where('workspace_id', $wid)->findOrFail($validated['stage_id']);

        $leads = Lead::where('workspace_id', $wid)
            ->whereIn('id', $validated['ids'])
            ->get();

        foreach ($leads as $lead) {
            $this->pipeline->moveLead($lead, $stage, 0, $request->user()->id);
        }

        return back()->with('success', __(':count lead(s) moved to :stage.', [
            'count' => $leads->count(),
            'stage' => $stage->name,
        ]));
    }

    public function tags(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['add', 'remove'])],
            'tag_names' => ['required', 'array', 'min:1', 'max:20'],
            'tag_names.*' => ['string', 'max:64'],
        ]);

        $leadIds = Lead::where('workspace_id', $wid)
            ->whereIn('id', $validated['ids'])
            ->pluck('id');

        $tagNames = array_values(array_unique(array_filter(array_map('trim', $validated['tag_names']))));

        if ($validated['action'] === 'add') {
            $tagIds = collect($tagNames)
                ->map(fn (string $name) => LeadTag::firstOrCreate(['workspace_id' => $wid, 'name' => $name])->id);

            $existing = DB::table('lead_tag_pivot')
                ->whereIn('lead_id', $leadIds)
                ->whereIn('tag_id', $tagIds)
                ->get(['lead_id', 'tag_id'])
                ->map(fn ($p) => $p->lead_id.':'.$p->tag_id)
                ->flip();

            $inserts = [];
            foreach ($leadIds as $leadId) {
                foreach ($tagIds as $tagId) {
                    if (! isset($existing[$leadId.':'.$tagId])) {
                        $inserts[] = ['lead_id' => $leadId, 'tag_id' => $tagId];
                    }
                }
            }
            if ($inserts !== []) {
                DB::table('lead_tag_pivot')->insert($inserts);
            }
        } else {
            $tagIds = LeadTag::where('workspace_id', $wid)->whereIn('name', $tagNames)->pluck('id');
            DB::table('lead_tag_pivot')
                ->whereIn('lead_id', $leadIds)
                ->whereIn('tag_id', $tagIds)
                ->delete();
        }

        return back()->with('success', __('Tags updated for :count lead(s).', ['count' => $leadIds->count()]));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
        ]);

        $deleted = Lead::where('workspace_id', $wid)
            ->whereIn('id', $validated['ids'])
            ->delete();

        return back()->with('success', __(':count lead(s) deleted.', ['count' => $deleted]));
    }
}

