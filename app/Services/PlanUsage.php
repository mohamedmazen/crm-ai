<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\Automation\Models\Automation;
use App\Modules\Broadcasting\Models\UsageMeter;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Shared\Models\Contact;

/**
 * Measures how much of each plan limit a workspace already uses.
 *
 * Only row-counted resources can be *over* a limit. Switching to a smaller plan
 * never deletes anything, so a workspace may sit above the new cap while all of
 * its data stays readable, exportable and usable. This service is what the
 * limit middleware, the shared Inertia props and the pricing page use to detect
 * and explain that state.
 *
 * Monthly meters (campaigns, messages, AI tokens, ...) reset every period, so
 * they can approach a cap but never start above one.
 */
class PlanUsage
{
    /**
     * Limit keys compared against rows that exist right now, with the label the
     * UI shows. `storage` is the exception: the plan stores megabytes while the
     * database stores bytes, so current() converts before returning.
     *
     * @var array<string, string>
     */
    public const ROW_COUNTED = [
        'max_contacts' => 'Contacts',
        'knowledge_bases' => 'Knowledge bases',
        'chatbots' => 'Chatbots',
        'pipelines' => 'Pipelines',
        'automations' => 'Automations',
        'users' => 'Team seats',
        'storage' => 'Storage',
    ];

    /**
     * Current amount for a limit key, in the unit the plan stores it in: row
     * counts, seats, or megabytes for storage. Null means the key is not a
     * row-counted resource (a monthly meter or an unmetered limit).
     */
    public function current(Workspace $workspace, string $limitKey): ?int
    {
        $raw = match ($limitKey) {
            'users' => $workspace->client?->seatUsage(),
            'storage' => $this->storageBytes($workspace),
            default => self::countedRows($workspace->id, $limitKey),
        };

        if ($raw === null) {
            return null;
        }

        return $limitKey === 'storage'
            ? (int) ceil($raw / 1024 / 1024) // bytes → megabytes, as plans store it
            : (int) $raw;
    }

    /**
     * Row counts shared with the limit middleware, keyed by the counter name
     * used in routes (`limit:chatbots`). Null means the key is a monthly meter.
     */
    public static function countedRows(int $workspaceId, string $counterKey): ?int
    {
        return match ($counterKey) {
            'max_contacts' => Contact::where('workspace_id', $workspaceId)->count(),
            'knowledge_bases' => AiKnowledgeBase::where('workspace_id', $workspaceId)->count(),
            'chatbots' => AiChatbot::where('workspace_id', $workspaceId)->count(),
            'pipelines' => LeadPipeline::where('workspace_id', $workspaceId)->count(),
            'automations' => Automation::where('workspace_id', $workspaceId)->count(),
            default => null,
        };
    }

    /**
     * Bytes stored by the workspace's users. Media is owned per user today, so
     * the workspace total is the sum of its members' files.
     */
    public function storageBytes(Workspace $workspace): int
    {
        $userIds = $workspace->users()->pluck('users.id');

        if ($userIds->isEmpty()) {
            return 0;
        }

        return (int) Media::where('mediable_type', User::class)
            ->whereIn('mediable_id', $userIds)
            ->sum('size_bytes');
    }

    /**
     * Counted resources that already exceed the plan, with the overage.
     *
     * @return array<int, array{key: string, label: string, current: int, limit: int, over_by: int}>
     */
    public function breaches(Workspace $workspace, Plan $plan): array
    {
        $breaches = [];

        foreach (self::ROW_COUNTED as $key => $label) {
            $limit = $plan->limitValue($key);

            if ($limit === null) {
                continue;
            }

            $current = $this->current($workspace, $key);

            if ($current === null || $current <= (int) $limit) {
                continue;
            }

            $breaches[] = [
                'key' => $key,
                'label' => $label,
                'current' => $current,
                'limit' => (int) $limit,
                'over_by' => $current - (int) $limit,
            ];
        }

        return $breaches;
    }

    /**
     * Shape shared with the SPA for every limit the plan defines.
     *
     * @return array<string, array{current:int, limit:int, percent:int, is_over:bool, over_by:int, unit:string}>
     */
    public function summary(Workspace $workspace, Plan $plan): array
    {
        $limits = $plan->limits;

        if (! is_array($limits) || $limits === []) {
            return [];
        }

        $summary = [];

        foreach ($limits as $key => $limit) {
            if ($limit === null) {
                continue;
            }

            if (! array_key_exists($key, self::ROW_COUNTED)) {
                // Monthly meter: resets each period and never starts "over".
                $current = UsageMeter::current($workspace->id, self::meterKey($key));
                $summary[$key] = $this->entry($current, (int) $limit, 'count');

                continue;
            }

            $summary[$key] = $this->entry(
                (int) ($this->current($workspace, $key) ?? 0),
                (int) $limit,
                $key === 'storage' ? 'mb' : 'count',
            );
        }

        return $summary;
    }

    /**
     * Map a plan limit key to the usage_meters metric that tracks it.
     */
    public static function meterKey(string $limitKey): string
    {
        return match ($limitKey) {
            'campaigns_per_month' => 'campaigns',
            'whatsapp_messages_per_month' => 'whatsapp_messages',
            'social_posts_per_month' => 'social_posts',
            'lead_credits_per_month' => 'lead_credits',
            'ai_tokens_per_month' => 'ai_tokens',
            default => $limitKey,
        };
    }

    /**
     * @return array{current:int, limit:int, percent:int, is_over:bool, over_by:int, unit:string}
     */
    private function entry(int $current, int $limit, string $unit): array
    {
        $percent = $limit > 0
            ? (int) min(100, round(($current / $limit) * 100))
            : ($current > 0 ? 100 : 0);

        return [
            'current' => $current,
            'limit' => $limit,
            'percent' => $percent,
            'is_over' => $current > $limit,
            'over_by' => max(0, $current - $limit),
            'unit' => $unit,
        ];
    }
}
