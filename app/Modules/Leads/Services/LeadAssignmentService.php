<?php

namespace App\Modules\Leads\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadAssignmentConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class LeadAssignmentService
{
    public function __construct(
        private readonly ActivityRecorder $activity,
    ) {}

    /**
     * Get active team members eligible for lead assignment in this workspace.
     *
     * @return Collection<int, User>
     */
    public function eligibleUsersFor(int $workspaceId): Collection
    {
        $workspace = Workspace::with('client')->find($workspaceId);

        if (! $workspace) {
            return new Collection();
        }

        // Return users belonging to this client or workspace who are active
        return User::query()
            ->where(function ($q) use ($workspace) {
                if ($workspace->client_id) {
                    $q->where('client_id', $workspace->client_id);
                } else {
                    $q->where('workspace_id', $workspace->id);
                }
            })
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get();
    }

    /**
     * Assign a lead manually to a user (or unassign when null).
     */
    public function assign(Lead $lead, ?User $user, ?User $actor = null): Lead
    {
        if ($lead->assigned_user_id === $user?->id) {
            return $lead;
        }

        $oldUser = $lead->assignedUser;

        DB::transaction(function () use ($lead, $user) {
            $lead->update([
                'assigned_user_id' => $user?->id,
                'assigned_at' => $user ? now() : null,
            ]);
        });

        $this->activity->assigned($lead, $user, $oldUser, $actor?->id ?? false);

        return $lead->refresh();
    }

    /**
     * Automatically assign a lead according to the pipeline's assignment mode.
     */
    public function autoAssign(Lead $lead): ?User
    {
        $pipeline = $lead->pipeline;

        if (! $pipeline) {
            return null;
        }

        $config = LeadAssignmentConfig::where('pipeline_id', $pipeline->id)->first();

        if (! $config || $config->mode === LeadAssignmentConfig::MODE_MANUAL) {
            return null;
        }

        $eligibleUsers = $this->eligibleUsersFor((int) $lead->workspace_id);

        if ($eligibleUsers->isEmpty()) {
            return null;
        }

        $targetUser = match ($config->mode) {
            LeadAssignmentConfig::MODE_ROUND_ROBIN => $this->selectRoundRobinUser($eligibleUsers, $config->last_assigned_user_id),
            LeadAssignmentConfig::MODE_LOAD_BALANCED => $this->selectLoadBalancedUser($eligibleUsers, (int) $lead->workspace_id),
            default => null,
        };

        if ($targetUser) {
            $config->update(['last_assigned_user_id' => $targetUser->id]);
            $this->assign($lead, $targetUser);
        }

        return $targetUser;
    }

    /**
     * Pick next user in the rotation list.
     *
     * @param  Collection<int, User>  $users
     */
    private function selectRoundRobinUser(Collection $users, ?int $lastAssignedUserId): User
    {
        $ordered = $users->values();

        if (! $lastAssignedUserId) {
            return $ordered->first();
        }

        $lastIndex = $ordered->search(fn ($u) => (int) $u->id === (int) $lastAssignedUserId);

        if ($lastIndex === false || $lastIndex >= $ordered->count() - 1) {
            return $ordered->first();
        }

        return $ordered->get($lastIndex + 1);
    }

    /**
     * Pick user with the fewest open/active leads in this workspace.
     *
     * @param  Collection<int, User>  $users
     */
    private function selectLoadBalancedUser(Collection $users, int $workspaceId): User
    {
        // Count open leads per user
        $counts = Lead::where('workspace_id', $workspaceId)
            ->whereIn('assigned_user_id', $users->pluck('id'))
            ->whereNotIn('status', ['converted', 'lost'])
            ->selectRaw('assigned_user_id, count(*) as count')
            ->groupBy('assigned_user_id')
            ->pluck('count', 'assigned_user_id');

        return $users->sortBy(fn ($user) => (int) ($counts[$user->id] ?? 0))->first();
    }
}

