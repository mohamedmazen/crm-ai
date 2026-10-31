<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Modules\Leads\Jobs\DetectDuplicatesJob;
use Illuminate\Console\Command;

class DetectDuplicateLeadsCommand extends Command
{
    protected $signature = 'leads:detect-duplicates';

    protected $description = 'Queue duplicate-lead detection for every workspace whose plan includes it';

    public function handle(): int
    {
        $dispatched = 0;

        Workspace::query()
            ->with('client.activeSubscription.plan')
            ->chunkById(100, function ($workspaces) use (&$dispatched) {
                foreach ($workspaces as $workspace) {
                    // The feature is per-plan, so a workspace only joins the scan
                    // when the plan actually granting its client access includes it.
                    if (! $workspace->client?->effectivePlan()?->hasFeature('duplicates')) {
                        continue;
                    }

                    DetectDuplicatesJob::dispatch($workspace->id)->onQueue('leads');
                    $dispatched++;
                }
            });

        $this->info("Queued duplicate detection for {$dispatched} workspace(s).");

        return self::SUCCESS;
    }
}
