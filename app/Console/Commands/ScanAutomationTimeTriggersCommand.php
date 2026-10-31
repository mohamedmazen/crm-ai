<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Modules\Automation\Jobs\ScanWorkspaceTimeTriggersJob;
use App\Modules\Automation\Models\Automation;
use Illuminate\Console\Command;

class ScanAutomationTimeTriggersCommand extends Command
{
    protected $signature = 'automation:scan-time-triggers';

    protected $description = 'Queue overdue-task, inactivity and custom-date automation triggers per workspace';

    /** @var list<string> */
    private const TRIGGER_TYPES = ['task.overdue', 'lead.inactive', 'lead.date_reached'];

    public function handle(): int
    {
        $dispatched = 0;

        Workspace::query()
            ->whereIn('id', Automation::query()
                ->where('status', 'active')
                ->whereIn('trigger_type', self::TRIGGER_TYPES)
                ->select('workspace_id'))
            ->chunkById(100, function ($workspaces) use (&$dispatched) {
                foreach ($workspaces as $workspace) {
                    ScanWorkspaceTimeTriggersJob::dispatch($workspace->id)->onQueue('automation');
                    $dispatched++;
                }
            });

        $this->info("Queued automation time-trigger scan for {$dispatched} workspace(s).");

        return self::SUCCESS;
    }
}
