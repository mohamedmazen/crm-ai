<?php

namespace App\Modules\Automation\Jobs;

use App\Modules\Automation\Services\TimeTriggerScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Runs the scheduled automation trigger scan for one workspace. Queued per
 * workspace so a large tenant cannot hold the scheduler open.
 */
class ScanWorkspaceTimeTriggersJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $workspaceId) {}

    /** One scan per workspace in flight. */
    public function uniqueId(): string
    {
        return 'automation-time-scan-'.$this->workspaceId;
    }

    public function handle(TimeTriggerScanner $scanner): void
    {
        $counts = $scanner->scanWorkspace($this->workspaceId);

        Log::info('Scanned automation time triggers', [
            'workspace_id' => $this->workspaceId,
            'task.overdue' => $counts['task.overdue'],
            'lead.inactive' => $counts['lead.inactive'],
            'lead.date_reached' => $counts['lead.date_reached'],
        ]);
    }
}
