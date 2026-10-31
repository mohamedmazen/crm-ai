<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Reporting\Services\SalesReportExporter;
use App\Modules\Reporting\Services\SalesReportService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use League\Csv\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pages and CSV exports for the sales reports (Phase 3).
 *
 * Every action is behind the `feature:sales_reports` route middleware; this
 * controller only deals with filters and result shaping.
 */
class SalesReportController extends Controller
{
    public function index(): RedirectResponse
    {
        return redirect()->route('client.reports.sales.volume');
    }

    public function volume(Request $request, SalesReportService $reports): Response
    {
        $workspaceId = $this->workspaceId($request);
        $filters = $this->filters($request);

        return Inertia::render('client/Reports/Sales/Volume', [
            ...$this->sharedProps($workspaceId, $filters, $reports),
            'rows' => $reports->leadVolume($workspaceId, $filters),
        ]);
    }

    public function sources(Request $request, SalesReportService $reports): Response
    {
        $workspaceId = $this->workspaceId($request);
        $filters = $this->filters($request);

        return Inertia::render('client/Reports/Sales/Sources', [
            ...$this->sharedProps($workspaceId, $filters, $reports),
            'rows' => $reports->sourcePerformance($workspaceId, $filters),
        ]);
    }

    public function winLoss(Request $request, SalesReportService $reports): Response
    {
        $workspaceId = $this->workspaceId($request);
        $filters = $this->filters($request);

        return Inertia::render('client/Reports/Sales/WinLoss', [
            ...$this->sharedProps($workspaceId, $filters, $reports),
            'rows' => $reports->winLoss($workspaceId, $filters),
        ]);
    }

    public function agents(Request $request, SalesReportService $reports): Response
    {
        $workspaceId = $this->workspaceId($request);
        $filters = $this->filters($request);

        return Inertia::render('client/Reports/Sales/Agents', [
            ...$this->sharedProps($workspaceId, $filters, $reports),
            'rows' => $reports->agentPerformance($workspaceId, $filters),
        ]);
    }

    public function response(Request $request, SalesReportService $reports): Response
    {
        $workspaceId = $this->workspaceId($request);
        $filters = $this->filters($request);
        $report = $reports->responseTime($workspaceId, $filters);

        return Inertia::render('client/Reports/Sales/Response', [
            ...$this->sharedProps($workspaceId, $filters, $reports),
            'rows' => $report['daily'],
            'responseSummary' => $report['summary'],
            'distribution' => $report['distribution'],
        ]);
    }

    public function pipeline(Request $request, SalesReportService $reports): Response
    {
        $workspaceId = $this->workspaceId($request);
        $filters = $this->filters($request);
        $funnel = $reports->pipelineFunnel($workspaceId, $filters);
        $filters['pipeline_id'] = $funnel['pipeline_id'];

        return Inertia::render('client/Reports/Sales/Pipeline', [
            ...$this->sharedProps($workspaceId, $filters, $reports),
            'rows' => $funnel['rows'],
            'bottlenecks' => $reports->stageBottlenecks($workspaceId, $filters),
            'pipelineName' => $funnel['pipeline_name'],
        ]);
    }

    public function attribution(Request $request, SalesReportService $reports): Response
    {
        $workspaceId = $this->workspaceId($request);
        $filters = $this->filters($request);

        return Inertia::render('client/Reports/Sales/Attribution', [
            ...$this->sharedProps($workspaceId, $filters, $reports),
            'rows' => $reports->sourceAttribution($workspaceId, $filters),
        ]);
    }

    public function export(Request $request, string $report, SalesReportExporter $exporter): StreamedResponse
    {
        abort_unless(in_array($report, SalesReportExporter::REPORT_TYPES, true), 404);

        $workspaceId = $this->workspaceId($request);
        $filters = $this->filters($request);

        ['filename' => $filename, 'header' => $header, 'rows' => $rows] = $exporter->table($workspaceId, $report, $filters);

        return $this->streamCsv($filename, $header, $rows);
    }

    /**
     * @return array{from: Carbon, to: Carbon, pipeline_id: ?int, agent_id: ?int, source: ?string}
     */
    private function filters(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'pipeline_id' => ['nullable', 'integer', 'min:1'],
            'agent_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:191'],
        ]);

        $from = $request->filled('from')
            ? Carbon::parse($request->input('from'))->startOfDay()
            : Carbon::now()->subDays(29)->startOfDay();

        $to = $request->filled('to')
            ? Carbon::parse($request->input('to'))->endOfDay()
            : Carbon::now()->endOfDay();

        return [
            'from' => $from,
            'to' => $to,
            'pipeline_id' => $request->filled('pipeline_id') ? $request->integer('pipeline_id') : null,
            'agent_id' => $request->filled('agent_id') ? $request->integer('agent_id') : null,
            'source' => $request->filled('source') ? (string) $request->input('source') : null,
        ];
    }

    /**
     * @param  array{from: Carbon, to: Carbon, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return array<string, mixed>
     */
    private function sharedProps(int $workspaceId, array $filters, SalesReportService $reports): array
    {
        return [
            'filters' => [
                'from' => $filters['from']->toDateString(),
                'to' => $filters['to']->toDateString(),
                'pipeline_id' => $filters['pipeline_id'],
                'agent_id' => $filters['agent_id'],
                'source' => $filters['source'],
            ],
            'pipelines' => LeadPipeline::where('workspace_id', $workspaceId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name']),
            'agents' => User::where('workspace_id', $workspaceId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'sources' => $reports->sourceOptions($workspaceId),
            'summary' => $reports->summary($workspaceId, $filters),
        ];
    }

    private function workspaceId(Request $request): int
    {
        $workspaceId = $request->user()->workspace_id;
        abort_if(! $workspaceId, 403);

        return (int) $workspaceId;
    }

    /**
     * @param  list<string>  $header
     * @param  list<array<int, mixed>>  $rows
     */
    private function streamCsv(string $filename, array $header, array $rows): StreamedResponse
    {
        return response()->stream(function () use ($header, $rows): void {
            $csv = Writer::createFromStream(fopen('php://output', 'w'));
            $csv->insertOne($header);

            foreach ($rows as $row) {
                $csv->insertOne($row);
            }
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
