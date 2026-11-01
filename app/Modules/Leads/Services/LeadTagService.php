<?php

namespace App\Modules\Leads\Services;

use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadTag;
use Illuminate\Database\Eloquent\Collection;

/**
 * Attaches tag names to a single lead, creating the tag when the workspace has
 * never used that name before.
 *
 * Kept apart from the bulk controller, which inserts the cross-product of many
 * leads and many tags in one statement; this is the single-lead path the AI
 * suggestion panel confirms one tag at a time.
 */
class LeadTagService
{
    /**
     * Adds the given names to the lead and returns the lead's full tag list.
     *
     * @param  array<int, string>  $names
     * @return Collection<int, LeadTag>
     */
    public function attach(Lead $lead, array $names): Collection
    {
        $workspaceId = (int) $lead->workspace_id;

        $tagIds = collect($this->normalise($names))
            ->map(fn (string $name) => LeadTag::firstOrCreate([
                'workspace_id' => $workspaceId,
                'name' => $name,
            ])->id);

        if ($tagIds->isNotEmpty()) {
            $lead->tags()->syncWithoutDetaching($tagIds->all());
        }

        return $lead->tags()->orderBy('name')->get();
    }

    /**
     * Trims, de-duplicates case-insensitively and drops names the column cannot
     * hold. Tag names are unique per workspace, so "Hot" and "hot" are one tag.
     *
     * @param  array<int, string>  $names
     * @return array<int, string>
     */
    public function normalise(array $names): array
    {
        $seen = [];

        foreach ($names as $name) {
            $clean = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');

            if ($clean === '' || mb_strlen($clean) > 64) {
                continue;
            }

            $key = mb_strtolower($clean);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = $clean;
        }

        return array_values($seen);
    }
}
