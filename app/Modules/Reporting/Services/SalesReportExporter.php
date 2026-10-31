<?php

namespace App\Modules\Reporting\Services;

use Carbon\CarbonInterface;
use League\Csv\Writer;

/**
 * Turns any sales report into a filename, a header row and data rows, so the
 * web export and the scheduled email delivery share one definition of each CSV.
 */
class SalesReportExporter
{
    /** @var list<string> */
    public const REPORT_TYPES = ['volume', 'sources', 'win-loss', 'agents', 'response', 'pipeline', 'attribution'];

    public function __construct(private readonly SalesReportService $reports) {}

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return array{filename: string, header: list<string>, rows: list<array<int, mixed>>}
     */
    public function table(int $workspaceId, string $report, array $filters): array
    {
        if ($report === 'pipeline') {
            $funnel = $this->reports->pipelineFunnel($workspaceId, $filters);
            $filters['pipeline_id'] = $funnel['pipeline_id'];
            $bottlenecks = collect($this->reports->stageBottlenecks($workspaceId, $filters))->keyBy('stage_id');

            return [
                'filename' => 'pipeline-funnel.csv',
                'header' => ['Stage', 'Leads', 'Conversion Rate (%)', 'Step Conversion Rate (%)', 'Avg Days in Stage'],
                'rows' => array_map(fn (array $row): array => [
                    $row['stage'],
                    $row['leads'],
                    $row['conversion_rate'],
                    $row['step_conversion_rate'],
                    $bottlenecks->get($row['stage_id'])['avg_days'] ?? null,
                ], $funnel['rows']),
            ];
        }

        if ($report === 'response') {
            $responseReport = $this->reports->responseTime($workspaceId, $filters);

            return [
                'filename' => 'response-time.csv',
                'header' => ['Date', 'Responses', 'Median (seconds)', 'P90 (seconds)'],
                'rows' => array_map(fn (array $row): array => [
                    $row['date'], $row['count'], $row['median_seconds'], $row['p90_seconds'],
                ], $responseReport['daily']),
            ];
        }

        return match ($report) {
            'volume' => [
                'filename' => 'lead-volume.csv',
                'header' => ['Date', 'Leads'],
                'rows' => array_map(fn (array $row): array => [$row['date'], $row['leads']], $this->reports->leadVolume($workspaceId, $filters)),
            ],
            'sources' => [
                'filename' => 'source-performance.csv',
                'header' => ['Source', 'Leads', 'Won', 'Lost', 'Conversion Rate (%)', 'Won Value'],
                'rows' => array_map(fn (array $row): array => [
                    $row['source'], $row['leads'], $row['won'], $row['lost'], $row['conversion_rate'], $row['won_value'],
                ], $this->reports->sourcePerformance($workspaceId, $filters)),
            ],
            'win-loss' => [
                'filename' => 'win-loss-reasons.csv',
                'header' => ['Type', 'Reason', 'Count', 'Value'],
                'rows' => array_map(fn (array $row): array => [$row['type'], $row['reason'], $row['count'], $row['value']], $this->reports->winLoss($workspaceId, $filters)),
            ],
            'agents' => [
                'filename' => 'agent-performance.csv',
                'header' => ['Agent', 'Leads', 'Won', 'Lost', 'Win Rate (%)', 'Won Value', 'Pipeline Value'],
                'rows' => array_map(fn (array $row): array => [
                    $row['name'], $row['leads'], $row['won'], $row['lost'], $row['win_rate'], $row['won_value'], $row['pipeline_value'],
                ], $this->reports->agentPerformance($workspaceId, $filters)),
            ],
            'attribution' => [
                'filename' => 'source-attribution.csv',
                'header' => ['UTM Source', 'UTM Medium', 'UTM Campaign', 'Leads', 'Won', 'Conversion Rate (%)', 'Won Value'],
                'rows' => array_map(fn (array $row): array => [
                    $row['utm_source'], $row['utm_medium'], $row['utm_campaign'], $row['leads'], $row['won'], $row['conversion_rate'], $row['won_value'],
                ], $this->reports->sourceAttribution($workspaceId, $filters)),
            ],
            default => throw new \InvalidArgumentException("Unknown sales report [{$report}]."),
        };
    }

    /**
     * Render the report as a CSV document, for attaching to an email.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return array{filename: string, content: string}
     */
    public function csv(int $workspaceId, string $report, array $filters): array
    {
        ['filename' => $filename, 'header' => $header, 'rows' => $rows] = $this->table($workspaceId, $report, $filters);

        $csv = Writer::createFromString('');
        $csv->insertOne($header);

        foreach ($rows as $row) {
            $csv->insertOne($row);
        }

        return ['filename' => $filename, 'content' => $csv->toString()];
    }
}
