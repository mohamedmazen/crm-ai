<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Leads\Models\LeadTask;
use App\Modules\Leads\Services\LeadAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class TaskDashboardController extends Controller
{
    public function __construct(
        private readonly LeadAssignmentService $assignment,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $user = $request->user();

        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:all,today,overdue,upcoming,completed'],
            'assigned' => ['nullable', 'string', 'max:32'],
            'priority' => ['nullable', 'string', 'in:low,medium,high'],
            'type' => ['nullable', 'string', 'in:call,email,whatsapp,meeting,todo'],
            'search' => ['nullable', 'string', 'max:128'],
        ]);

        $status = $filters['status'] ?? 'today';
        $assigned = $filters['assigned'] ?? 'me';

        $query = LeadTask::where('workspace_id', $wid)
            ->with([
                'lead:id,name,phone,category,stage_id,pipeline_id',
                'assignedUser:id,name',
                'creator:id,name',
            ]);

        // Filter by assigned user
        if ($assigned === 'me') {
            $query->where('assigned_user_id', $user->id);
        } elseif ($assigned === 'unassigned') {
            $query->whereNull('assigned_user_id');
        } elseif (is_numeric($assigned)) {
            $query->where('assigned_user_id', (int) $assigned);
        }

        // Filter by status
        match ($status) {
            'today' => $query->dueToday(),
            'overdue' => $query->overdue(),
            'upcoming' => $query->upcoming(),
            'completed' => $query->completed(),
            'all' => $query->pending(),
            default => $query->dueToday(),
        };

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($term) {
                $q->where('title', 'like', $term)
                    ->orWhere('description', 'like', $term)
                    ->orWhereHas('lead', fn ($lq) => $lq->where('name', 'like', $term));
            });
        }

        if ($status === 'completed') {
            $query->orderByDesc('completed_at');
        } else {
            $query->orderByRaw('due_at IS NULL, due_at ASC')->orderByDesc('priority');
        }

        $tasks = $query->paginate(30)->withQueryString();

        // Calculate workspace / user counts for tabs
        $scopedCount = fn () => LeadTask::where('workspace_id', $wid)
            ->when($assigned === 'me', fn ($q) => $q->where('assigned_user_id', $user->id))
            ->when($assigned === 'unassigned', fn ($q) => $q->whereNull('assigned_user_id'))
            ->when(is_numeric($assigned), fn ($q) => $q->where('assigned_user_id', (int) $assigned));

        $counts = [
            'today' => (clone $scopedCount)()->dueToday()->count(),
            'overdue' => (clone $scopedCount)()->overdue()->count(),
            'upcoming' => (clone $scopedCount)()->upcoming()->count(),
            'completed' => (clone $scopedCount)()->completed()->count(),
            'all' => (clone $scopedCount)()->pending()->count(),
        ];

        $teamMembers = $this->assignment->eligibleUsersFor($wid)->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ])->values();

        return Inertia::render('Tasks/Index', [
            'tasks' => $tasks,
            'filters' => array_merge($filters, ['status' => $status, 'assigned' => $assigned]),
            'counts' => $counts,
            'teamMembers' => $teamMembers,
            'types' => LeadTask::TYPES,
            'priorities' => LeadTask::PRIORITIES,
        ]);
    }
}
