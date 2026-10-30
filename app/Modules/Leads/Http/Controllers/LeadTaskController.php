<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadTask;
use App\Modules\Leads\Services\ActivityRecorder;
use App\Modules\Leads\Services\LeadAssignmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadTaskController extends Controller
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly LeadAssignmentService $assignment,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function store(Request $request, Lead $lead): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $lead->workspace_id === $wid, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:256'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::in(LeadTask::TYPES)],
            'priority' => ['required', Rule::in(LeadTask::PRIORITIES)],
            'due_at' => ['nullable', 'date'],
            'assigned_user_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['assigned_user_id'])) {
            $eligible = $this->assignment->eligibleUsersFor($wid);
            if (! $eligible->contains('id', (int) $data['assigned_user_id'])) {
                abort(403, 'Assigned user does not belong to this organization.');
            }
        }

        $task = LeadTask::create([
            'workspace_id' => $wid,
            'lead_id' => $lead->id,
            'assigned_user_id' => $data['assigned_user_id'] ?? $lead->assigned_user_id ?? $request->user()->id,
            'created_by' => $request->user()->id,
            'title' => $data['title'],
            'description' => $data['description'] ?? null,
            'type' => $data['type'],
            'priority' => $data['priority'],
            'due_at' => $data['due_at'] ?? null,
        ]);

        $this->activity->taskCreated($lead, $task);

        return back()->with('success', __('Task created successfully.'));
    }

    public function update(Request $request, LeadTask $task): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $task->workspace_id === $wid, 403);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:256'],
            'description' => ['nullable', 'string', 'max:2000'],
            'type' => ['required', Rule::in(LeadTask::TYPES)],
            'priority' => ['required', Rule::in(LeadTask::PRIORITIES)],
            'due_at' => ['nullable', 'date'],
            'assigned_user_id' => ['nullable', 'integer'],
        ]);

        if (! empty($data['assigned_user_id'])) {
            $eligible = $this->assignment->eligibleUsersFor($wid);
            if (! $eligible->contains('id', (int) $data['assigned_user_id'])) {
                abort(403, 'Assigned user does not belong to this organization.');
            }
        }

        $task->update($data);

        return back()->with('success', __('Task updated successfully.'));
    }

    public function toggle(Request $request, LeadTask $task): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $task->workspace_id === $wid, 403);

        if ($task->isCompleted()) {
            $task->update(['completed_at' => null]);
            $message = __('Task marked as pending.');
        } else {
            $task->update(['completed_at' => now()]);
            if ($task->lead) {
                $this->activity->taskCompleted($task->lead, $task);
            }
            $message = __('Task completed!');
        }

        return back()->with('success', $message);
    }

    public function destroy(Request $request, LeadTask $task): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $task->workspace_id === $wid, 403);

        $task->delete();

        return back()->with('success', __('Task deleted.'));
    }
}
