<?php

namespace App\Modules\Leads\Services;

use App\Modules\Leads\Models\Company;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadDuplicateGroup;
use Illuminate\Support\Facades\DB;

class DuplicateDetector
{
    /**
     * Scan workspace leads for duplicates by phone, email, and website/domain.
     *
     * @return int Number of pending duplicate groups
     */
    public function scanWorkspace(int $workspaceId): int
    {
        $createdOrUpdated = 0;

        // 1. Scan by Phone (non-empty)
        $duplicatePhones = Lead::where('workspace_id', $workspaceId)
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->select('phone')
            ->groupBy('phone')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('phone');

        foreach ($duplicatePhones as $phone) {
            $leadIds = Lead::where('workspace_id', $workspaceId)
                ->where('phone', $phone)
                ->pluck('id')
                ->all();

            if (count($leadIds) >= 2) {
                $this->recordGroup($workspaceId, 'phone', $phone, $leadIds);
                $createdOrUpdated++;
            }
        }

        // 2. Scan by Email (non-empty)
        $duplicateEmails = Lead::where('workspace_id', $workspaceId)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->selectRaw('LOWER(email) as lower_email')
            ->groupBy('lower_email')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('lower_email');

        foreach ($duplicateEmails as $email) {
            $leadIds = Lead::where('workspace_id', $workspaceId)
                ->whereRaw('LOWER(email) = ?', [$email])
                ->pluck('id')
                ->all();

            if (count($leadIds) >= 2) {
                $this->recordGroup($workspaceId, 'email', $email, $leadIds);
                $createdOrUpdated++;
            }
        }

        // 3. Scan by Website / Domain
        $websites = Lead::where('workspace_id', $workspaceId)
            ->whereNotNull('website')
            ->where('website', '!=', '')
            ->pluck('website', 'id');

        $domainGroups = [];
        foreach ($websites as $leadId => $website) {
            $domain = Company::extractDomain($website);
            if ($domain) {
                $domainGroups[$domain][] = $leadId;
            }
        }

        foreach ($domainGroups as $domain => $leadIds) {
            if (count($leadIds) >= 2) {
                $this->recordGroup($workspaceId, 'website', $domain, $leadIds);
                $createdOrUpdated++;
            }
        }

        // 4. Clean up any pending groups with fewer than 2 active leads
        $pendingGroups = LeadDuplicateGroup::where('workspace_id', $workspaceId)
            ->pending()
            ->withCount('leads')
            ->get();

        foreach ($pendingGroups as $group) {
            if ($group->leads_count < 2) {
                $group->delete();
            }
        }

        return LeadDuplicateGroup::where('workspace_id', $workspaceId)->pending()->count();
    }

    /**
     * Record or update a duplicate group with member leads.
     *
     * @param  array<int>  $leadIds
     */
    private function recordGroup(int $workspaceId, string $matchType, string $matchValue, array $leadIds): void
    {
        sort($leadIds);

        // Check if a pending group already exists containing these exact leads
        $existingGroups = LeadDuplicateGroup::where('workspace_id', $workspaceId)
            ->pending()
            ->with('leads:id')
            ->get();

        foreach ($existingGroups as $group) {
            $existingLeadIds = $group->leads->pluck('id')->sort()->values()->all();
            if ($existingLeadIds === array_values($leadIds)) {
                // Already grouped
                return;
            }
        }

        DB::transaction(function () use ($workspaceId, $matchType, $matchValue, $leadIds) {
            $group = LeadDuplicateGroup::create([
                'workspace_id' => $workspaceId,
                'match_type' => $matchType,
                'match_value' => $matchValue,
                'status' => LeadDuplicateGroup::STATUS_PENDING,
            ]);

            $group->leads()->sync($leadIds);
        });
    }
}
