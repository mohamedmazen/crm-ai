<?php

namespace App\Modules\Automation\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Ledger that makes the scheduled automation triggers (task.overdue,
 * lead.inactive, lead.date_reached) fire exactly once per subject per window.
 *
 * claim() is an atomic insertOrIgnore() against the unique
 * (automation, trigger, subject, bucket) key, so a retried or duplicated scan
 * cannot dispatch the same trigger twice.
 */
class AutomationTriggerDispatch extends Model
{
    protected $table = 'automation_trigger_dispatches';

    protected $fillable = [
        'workspace_id',
        'automation_id',
        'trigger_type',
        'subject_type',
        'subject_id',
        'bucket',
        'claimed_at',
    ];

    protected function casts(): array
    {
        return [
            'claimed_at' => 'datetime',
        ];
    }

    /**
     * Try to claim one dispatch. Returns true only for the caller that won it.
     *
     * @param  string  $subjectType  'lead' or 'lead_task'
     * @param  string  $bucket  window key — a date, an inactivity reference date, or 'overdue'
     */
    public static function claim(
        int $workspaceId,
        int $automationId,
        string $triggerType,
        string $subjectType,
        int $subjectId,
        string $bucket,
    ): bool {
        $now = now();

        return DB::table('automation_trigger_dispatches')->insertOrIgnore([
            'workspace_id' => $workspaceId,
            'automation_id' => $automationId,
            'trigger_type' => $triggerType,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'bucket' => $bucket,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;
    }
}
