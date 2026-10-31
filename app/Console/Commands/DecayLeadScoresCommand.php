<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Modules\Leads\Jobs\DecayLeadScoresJob;
use Illuminate\Console\Command;

class DecayLeadScoresCommand extends Command
{
    protected $signature = 'leads:decay-scores';

    protected $description = 'Queue behavioral score decay for every workspace whose plan includes it';

    public function handle(): int
    {
        $dispatched = 0;

        Workspace::query()
            ->with('client.activeSubscription.plan')
            ->chunkById(100, function ($workspaces) use (&$dispatched) {
                foreach ($workspaces as $workspace) {
                    // Behavioral scoring rides on the same gate as the sales
                    // reports, so the scan only visits paying workspaces.
                    if (! $workspace->client?->effectivePlan()?->hasFeature('sales_reports')) {
                        continue;
                    }

                    DecayLeadScoresJob::dispatch($workspace->id)->onQueue('leads');
                    $dispatched++;
                }
            });

        $this->info("Queued score decay for {$dispatched} workspace(s).");

        return self::SUCCESS;
    }
}
