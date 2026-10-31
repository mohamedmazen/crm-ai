<?php

namespace App\Modules\Reporting\Jobs;

use App\Mail\ScheduledReportMail;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Reporting\Services\SalesReportExporter;
use App\Services\FeatureGate;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Builds one scheduled report and emails it with the CSV attached. The row may
 * have been deleted or the plan downgraded between queueing and execution.
 */
class SendScheduledReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $scheduleId,
    ) {}

    public function handle(SalesReportExporter $exporter): void
    {
        $schedule = ReportSchedule::with('workspace')->find($this->scheduleId);

        if (! $schedule || ! $schedule->workspace) {
            return;
        }

        if (! FeatureGate::forWorkspace($schedule->workspace)->enabled('sales_reports')) {
            return;
        }

        $recipients = $schedule->recipients ?? [];

        if ($recipients === []) {
            return;
        }

        $period = ReportSchedule::periodFor($schedule->frequency, now());
        $filters = [
            'from' => $period['from'],
            'to' => $period['to'],
            'pipeline_id' => $schedule->filters['pipeline_id'] ?? null,
            'agent_id' => $schedule->filters['agent_id'] ?? null,
            'source' => $schedule->filters['source'] ?? null,
        ];

        ['filename' => $filename, 'content' => $csv] = $exporter->csv(
            (int) $schedule->workspace_id,
            $schedule->report_type,
            $filters,
        );

        Mail::to($recipients)->queue(new ScheduledReportMail(
            schedule: $schedule,
            period: $this->periodLabel($period['from'], $period['to'], $schedule->frequency),
            filename: $filename,
            csv: $csv,
        ));
    }

    /**
     * Human-readable window shown in the email, e.g. "Oct 20 – Oct 26, 2026".
     */
    private function periodLabel(CarbonInterface $from, CarbonInterface $to, string $frequency): string
    {
        return match ($frequency) {
            'weekly' => $from->format('M j').' – '.$to->format('M j, Y'),
            'monthly' => $from->format('F Y'),
            default => $from->format('M j, Y'),
        };
    }
}
