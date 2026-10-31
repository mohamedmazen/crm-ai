<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Reporting\Services\SalesReportExporter;
use App\Modules\Reporting\Services\SalesReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * CRUD for recurring sales-report emails. The route group is gated on
 * `feature:sales_reports`; delivery itself happens in ProcessScheduledReports.
 */
class ReportScheduleController extends Controller
{
    public function index(Request $request, SalesReportService $reports): Response
    {
        $workspaceId = $this->workspaceId($request);

        return Inertia::render('client/Reports/Sales/Schedules', [
            'schedules' => ReportSchedule::where('workspace_id', $workspaceId)
                ->orderBy('next_run_at')
                ->orderBy('id')
                ->get()
                ->map(fn (ReportSchedule $schedule): array => [
                    'id' => $schedule->id,
                    'report_type' => $schedule->report_type,
                    'frequency' => $schedule->frequency,
                    'recipients' => $schedule->recipients,
                    'filters' => $schedule->filters ?? [],
                    'next_run_at' => $schedule->next_run_at?->toIso8601String(),
                ])
                ->all(),
            'reportTypes' => SalesReportExporter::REPORT_TYPES,
            'frequencies' => ReportSchedule::FREQUENCIES,
            'pipelines' => LeadPipeline::where('workspace_id', $workspaceId)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(['id', 'name']),
            'agents' => User::where('workspace_id', $workspaceId)
                ->orderBy('name')
                ->get(['id', 'name']),
            'sources' => $reports->sourceOptions($workspaceId),
            'defaultRecipients' => (string) $request->user()->email,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);
        $attributes = $this->validated($request);

        ReportSchedule::create([
            ...$attributes,
            'workspace_id' => $workspaceId,
            'format' => 'csv',
            'next_run_at' => ReportSchedule::nextRunAt($attributes['frequency'], now()),
        ]);

        return back()->with('success', __('Scheduled report created.'));
    }

    public function update(Request $request, int $schedule): RedirectResponse
    {
        $workspaceId = $this->workspaceId($request);
        $attributes = $this->validated($request);

        $this->schedule($workspaceId, $schedule)->update($attributes);

        return back()->with('success', __('Scheduled report updated.'));
    }

    public function destroy(Request $request, int $schedule): RedirectResponse
    {
        $this->schedule($this->workspaceId($request), $schedule)->delete();

        return back()->with('success', __('Scheduled report deleted.'));
    }

    private function schedule(int $workspaceId, int $scheduleId): ReportSchedule
    {
        return ReportSchedule::where('workspace_id', $workspaceId)->findOrFail($scheduleId);
    }

    /**
     * @return array{report_type: string, frequency: string, recipients: list<string>, filters: array<string, mixed>}
     */
    private function validated(Request $request): array
    {
        $validated = $request->validate([
            'report_type' => ['required', Rule::in(SalesReportExporter::REPORT_TYPES)],
            'frequency' => ['required', Rule::in(ReportSchedule::FREQUENCIES)],
            'recipients' => ['required', 'string', 'max:1000'],
            'pipeline_id' => ['nullable', 'integer', 'min:1'],
            'agent_id' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'string', 'max:191'],
        ]);

        $emails = collect(preg_split('/[\s,;]+/', $validated['recipients'], -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->unique()
            ->values();

        $invalid = $emails->contains(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) === false);

        if ($emails->isEmpty() || $emails->count() > 10 || $invalid) {
            throw ValidationException::withMessages([
                'recipients' => __('Enter up to 10 valid email addresses.'),
            ]);
        }

        return [
            'report_type' => $validated['report_type'],
            'frequency' => $validated['frequency'],
            'recipients' => $emails->all(),
            'filters' => array_filter([
                'pipeline_id' => isset($validated['pipeline_id']) ? (int) $validated['pipeline_id'] : null,
                'agent_id' => isset($validated['agent_id']) ? (int) $validated['agent_id'] : null,
                'source' => $validated['source'] ?? null,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
        ];
    }

    private function workspaceId(Request $request): int
    {
        $workspaceId = $request->user()->workspace_id;
        abort_if(! $workspaceId, 403);

        return (int) $workspaceId;
    }
}
