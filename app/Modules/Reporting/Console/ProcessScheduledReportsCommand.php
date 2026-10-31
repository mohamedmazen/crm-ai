<?php

namespace App\Modules\Reporting\Console;

use App\Modules\Reporting\Jobs\SendScheduledReportJob;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Services\FeatureGate;
use Illuminate\Console\Command;

/**
 * Queues every scheduled report whose delivery slot has arrived, then moves the
 * schedule on to its next slot so a missed tick cannot cause duplicates.
 */
class ProcessScheduledReportsCommand extends Command
{
    protected $signature = 'reports:process-scheduled {--limit=100 : Maximum schedules to process per run}';

    protected $description = 'Queue the sales reports due for scheduled email delivery.';

    public function handle(): int
    {
        $due = ReportSchedule::with('workspace')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now())
            ->orderBy('next_run_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        $queued = 0;

        foreach ($due as $schedule) {
            // Downgrades keep their schedules but stop receiving reports. Advancing
            // the slot means an upgrade resumes delivery without a backlog.
            $enabled = $schedule->workspace
                && FeatureGate::forWorkspace($schedule->workspace)->enabled('sales_reports');

            if ($enabled) {
                SendScheduledReportJob::dispatch($schedule->id);
                $queued++;
            }

            $schedule->advance();
        }

        $this->info("Queued {$queued} scheduled report(s).");

        return self::SUCCESS;
    }
}
