<?php

namespace App\Modules\Automation\Services;

use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationTriggerDispatch;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadTask;
use App\Modules\Shared\Models\CustomFieldValue;

/**
 * Dispatches the automation triggers that have no originating event of their own:
 * overdue tasks, inactive leads and a lead custom date field reaching today.
 *
 * Each subject is claimed in automation_trigger_dispatches before the run starts,
 * so a retried or duplicated scan (or two schedulers racing) fires exactly once
 * per automation + subject + window.
 *
 * All three triggers remain contact-based: a lead with no pushed contact is
 * skipped, matching every other lead automation.
 */
class TimeTriggerScanner
{
    public function __construct(private readonly AutomationEngine $engine) {}

    /**
     * @return array<string, int> number of runs dispatched per trigger type
     */
    public function scanWorkspace(int $workspaceId): array
    {
        $counts = [
            'task.overdue' => 0,
            'lead.inactive' => 0,
            'lead.date_reached' => 0,
        ];

        $automations = Automation::where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->whereIn('trigger_type', array_keys($counts))
            ->get();

        foreach ($automations as $automation) {
            $counts[$automation->trigger_type] += match ($automation->trigger_type) {
                'task.overdue' => $this->scanOverdueTasks($automation),
                'lead.inactive' => $this->scanInactiveLeads($automation),
                'lead.date_reached' => $this->scanDateReached($automation),
                default => 0,
            };
        }

        return $counts;
    }

    /** Fires once per task, ever: the bucket is a constant. */
    private function scanOverdueTasks(Automation $automation): int
    {
        $workspaceId = (int) $automation->workspace_id;
        $dispatched = 0;

        LeadTask::where('workspace_id', $workspaceId)
            ->overdue()
            ->whereHas('lead', fn ($query) => $query->whereHas('contact'))
            ->with('lead.contact:id,lead_id')
            ->chunkById(200, function ($tasks) use ($automation, $workspaceId, &$dispatched) {
                foreach ($tasks as $task) {
                    $contactId = $task->lead?->contact?->id;
                    if (! $contactId) {
                        continue;
                    }

                    $claimed = AutomationTriggerDispatch::claim(
                        $workspaceId,
                        (int) $automation->id,
                        'task.overdue',
                        'lead_task',
                        (int) $task->id,
                        'overdue',
                    );

                    if (! $claimed) {
                        continue;
                    }

                    $this->engine->triggerForContact($automation, (int) $contactId, [
                        'lead_id' => (string) $task->lead_id,
                        'task_id' => (string) $task->id,
                        'task_title' => (string) $task->title,
                        'task_due_at' => $task->due_at?->toIso8601String() ?? '',
                    ]);

                    $dispatched++;
                }
            });

        return $dispatched;
    }

    /**
     * Fires once per inactivity window. The bucket is the reference date
     * (last activity, else creation), so fresh activity re-arms the trigger
     * while an ongoing silence does not repeat it.
     */
    private function scanInactiveLeads(Automation $automation): int
    {
        $days = (int) ($automation->trigger_config['days'] ?? 0);
        if ($days <= 0) {
            return 0;
        }

        $workspaceId = (int) $automation->workspace_id;
        $cutoff = now()->subDays($days);
        $dispatched = 0;

        Lead::where('workspace_id', $workspaceId)
            ->whereNull('won_at')
            ->whereNull('lost_at')
            ->whereHas('contact')
            ->where(function ($query) use ($cutoff) {
                $query->where('last_activity_at', '<=', $cutoff)
                    ->orWhere(function ($query) use ($cutoff) {
                        $query->whereNull('last_activity_at')->where('created_at', '<=', $cutoff);
                    });
            })
            ->with('contact:id,lead_id')
            ->chunkById(200, function ($leads) use ($automation, $workspaceId, $days, &$dispatched) {
                foreach ($leads as $lead) {
                    $contactId = $lead->contact?->id;
                    if (! $contactId) {
                        continue;
                    }

                    $reference = $lead->last_activity_at ?? $lead->created_at;
                    $bucket = $reference?->toDateString() ?? now()->toDateString();

                    $claimed = AutomationTriggerDispatch::claim(
                        $workspaceId,
                        (int) $automation->id,
                        'lead.inactive',
                        'lead',
                        (int) $lead->id,
                        $bucket,
                    );

                    if (! $claimed) {
                        continue;
                    }

                    $this->engine->triggerForContact($automation, (int) $contactId, [
                        'lead_id' => (string) $lead->id,
                        'inactive_days' => (string) $days,
                    ]);

                    $dispatched++;
                }
            });

        return $dispatched;
    }

    /** Fires when a date-type lead custom field equals today; bucket is today. */
    private function scanDateReached(Automation $automation): int
    {
        $fieldId = (int) ($automation->trigger_config['field_id'] ?? 0);
        if ($fieldId <= 0) {
            return 0;
        }

        $workspaceId = (int) $automation->workspace_id;
        $today = now()->toDateString();

        $leadIds = CustomFieldValue::query()
            ->where('workspace_id', $workspaceId)
            ->where('field_id', $fieldId)
            ->where('entity_type', 'lead')
            ->whereDate('value', $today)
            ->pluck('entity_id')
            ->unique()
            ->values();

        if ($leadIds->isEmpty()) {
            return 0;
        }

        $dispatched = 0;

        Lead::where('workspace_id', $workspaceId)
            ->whereIn('id', $leadIds)
            ->whereNull('won_at')
            ->whereNull('lost_at')
            ->whereHas('contact')
            ->with('contact:id,lead_id')
            ->chunkById(200, function ($leads) use ($automation, $workspaceId, $today, $fieldId, &$dispatched) {
                foreach ($leads as $lead) {
                    $contactId = $lead->contact?->id;
                    if (! $contactId) {
                        continue;
                    }

                    $claimed = AutomationTriggerDispatch::claim(
                        $workspaceId,
                        (int) $automation->id,
                        'lead.date_reached',
                        'lead',
                        (int) $lead->id,
                        $today,
                    );

                    if (! $claimed) {
                        continue;
                    }

                    $this->engine->triggerForContact($automation, (int) $contactId, [
                        'lead_id' => (string) $lead->id,
                        'field_id' => (string) $fieldId,
                        'date_reached' => $today,
                    ]);

                    $dispatched++;
                }
            });

        return $dispatched;
    }
}
