<?php

namespace App\Modules\Reporting\Services;

use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Leads\Models\LeadStageHistory;
use App\Modules\Shared\Models\Conversation;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only aggregations for the sales reports.
 *
 * Every report shares the same filters (date range, pipeline, agent and source)
 * and the same workspace scope, so all of them start from leadQuery().
 */
class SalesReportService
{
    /**
     * A lead counts as won/lost when the pipeline set the timestamp or the
     * status, whichever happened. Keeping both keeps reports correct for leads
     * that were imported or edited outside the pipeline board.
     */
    private const WON_SQL = "(leads.won_at IS NOT NULL OR leads.status = 'converted')";

    private const LOST_SQL = "(leads.lost_at IS NOT NULL OR leads.status = 'lost')";

    /** Display label for a lead's attribution: named source first, then text. */
    private const SOURCE_LABEL_SQL = "COALESCE(NULLIF(lead_sources.name, ''), NULLIF(leads.source, ''), 'Unknown')";

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return list<array{date: string, leads: int}>
     */
    public function leadVolume(int $workspaceId, array $filters): array
    {
        $counts = $this->leadQuery($workspaceId, $filters)
            ->selectRaw('DATE(leads.created_at) as date, COUNT(*) as leads')
            ->groupByRaw('DATE(leads.created_at)')
            ->orderByRaw('DATE(leads.created_at)')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->date => (int) $row->leads])
            ->all();

        return $this->fillMissingDays($filters['from'], $filters['to'], $counts);
    }

    /**
     * Leads, wins, losses, conversion and won value per attribution source.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return list<array{source: string, leads: int, won: int, lost: int, conversion_rate: float, won_value: float}>
     */
    public function sourcePerformance(int $workspaceId, array $filters): array
    {
        return $this->leadQuery($workspaceId, $filters)
            ->selectRaw(self::SOURCE_LABEL_SQL.' as source')
            ->selectRaw('COUNT(*) as leads')
            ->selectRaw('SUM(CASE WHEN '.self::WON_SQL.' THEN 1 ELSE 0 END) as won')
            ->selectRaw('SUM(CASE WHEN '.self::LOST_SQL.' THEN 1 ELSE 0 END) as lost')
            ->selectRaw('COALESCE(SUM(CASE WHEN '.self::WON_SQL.' THEN leads.deal_value ELSE 0 END), 0) as won_value')
            ->groupByRaw(self::SOURCE_LABEL_SQL)
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(function ($row): array {
                $leads = (int) $row->leads;
                $won = (int) $row->won;

                return [
                    'source' => (string) $row->source,
                    'leads' => $leads,
                    'won' => $won,
                    'lost' => (int) $row->lost,
                    'conversion_rate' => $leads > 0 ? round(($won / $leads) * 100, 2) : 0.0,
                    'won_value' => round((float) $row->won_value, 2),
                ];
            })
            ->all();
    }

    /**
     * Closed leads in the period, grouped by outcome and close reason.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return list<array{type: string, reason: string, count: int, value: float}>
     */
    public function winLoss(int $workspaceId, array $filters): array
    {
        return Lead::query()
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->leftJoin('lead_close_reasons', 'lead_close_reasons.id', '=', 'leads.close_reason_id')
            ->where('leads.workspace_id', $workspaceId)
            ->where(function (Builder $query) use ($filters): void {
                $query->whereBetween('leads.won_at', [$filters['from'], $filters['to']])
                    ->orWhereBetween('leads.lost_at', [$filters['from'], $filters['to']]);
            })
            ->tap(fn (Builder $query) => $this->applySharedFilters($query, $filters))
            ->selectRaw("CASE WHEN leads.won_at IS NOT NULL THEN 'won' ELSE 'lost' END as type")
            ->selectRaw("COALESCE(NULLIF(lead_close_reasons.name, ''), 'Unspecified') as reason")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('COALESCE(SUM(leads.deal_value), 0) as value')
            ->groupByRaw("CASE WHEN leads.won_at IS NOT NULL THEN 'won' ELSE 'lost' END")
            ->groupByRaw("COALESCE(NULLIF(lead_close_reasons.name, ''), 'Unspecified')")
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(fn ($row): array => [
                'type' => (string) $row->type,
                'reason' => (string) $row->reason,
                'count' => (int) $row->count,
                'value' => round((float) $row->value, 2),
            ])
            ->all();
    }

    /**
     * Win rate, won value and open pipeline value per assigned agent.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return list<array{user_id: int, name: string, leads: int, won: int, lost: int, win_rate: float, won_value: float, pipeline_value: float}>
     */
    public function agentPerformance(int $workspaceId, array $filters): array
    {
        return $this->leadQuery($workspaceId, $filters)
            ->join('users', 'users.id', '=', 'leads.assigned_user_id')
            ->selectRaw('users.id as user_id, users.name as name')
            ->selectRaw('COUNT(*) as leads')
            ->selectRaw('SUM(CASE WHEN '.self::WON_SQL.' THEN 1 ELSE 0 END) as won')
            ->selectRaw('SUM(CASE WHEN '.self::LOST_SQL.' THEN 1 ELSE 0 END) as lost')
            ->selectRaw('COALESCE(SUM(CASE WHEN '.self::WON_SQL.' THEN leads.deal_value ELSE 0 END), 0) as won_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN NOT '.self::WON_SQL.' AND NOT '.self::LOST_SQL.' THEN leads.deal_value ELSE 0 END), 0) as pipeline_value')
            ->groupBy('users.id', 'users.name')
            ->orderByRaw('COALESCE(SUM(CASE WHEN '.self::WON_SQL.' THEN leads.deal_value ELSE 0 END), 0) DESC')
            ->get()
            ->map(function ($row): array {
                $won = (int) $row->won;
                $lost = (int) $row->lost;
                $closed = $won + $lost;

                return [
                    'user_id' => (int) $row->user_id,
                    'name' => (string) $row->name,
                    'leads' => (int) $row->leads,
                    'won' => $won,
                    'lost' => $lost,
                    'win_rate' => $closed > 0 ? round(($won / $closed) * 100, 2) : 0.0,
                    'won_value' => round((float) $row->won_value, 2),
                    'pipeline_value' => round((float) $row->pipeline_value, 2),
                ];
            })
            ->all();
    }

    /**
     * First-response latency for conversations that got a first reply in the
     * period: median, P90, a daily trend and response-time buckets.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return array{
     *     summary: array{count: int, median_seconds: int|null, p90_seconds: int|null, avg_seconds: int|null},
     *     daily: list<array{date: string, count: int, median_seconds: int|null, p90_seconds: int|null}>,
     *     distribution: list<array{bucket: string, count: int}>
     * }
     */
    public function responseTime(int $workspaceId, array $filters): array
    {
        $rows = Conversation::query()
            ->leftJoin('contacts', 'contacts.id', '=', 'conversations.contact_id')
            ->leftJoin('leads', 'leads.id', '=', 'contacts.lead_id')
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->where('conversations.workspace_id', $workspaceId)
            ->whereNotNull('conversations.first_response_at')
            ->whereBetween('conversations.first_response_at', [$filters['from'], $filters['to']])
            ->when($filters['agent_id'], fn (Builder $query, int $id) => $query->where('conversations.assigned_user_id', $id))
            ->when($filters['pipeline_id'], fn (Builder $query, int $id) => $query->where('leads.pipeline_id', $id))
            ->when($filters['source'], fn (Builder $query, string $source) => $query->whereRaw(self::SOURCE_LABEL_SQL.' = ?', [$source]))
            ->selectRaw('DATE(conversations.first_response_at) as date')
            ->selectRaw('GREATEST(TIMESTAMPDIFF(SECOND, conversations.created_at, conversations.first_response_at), 0) as seconds')
            ->get();

        $byDate = [];
        $all = [];

        foreach ($rows as $row) {
            $seconds = (int) $row->seconds;
            $byDate[(string) $row->date][] = $seconds;
            $all[] = $seconds;
        }

        sort($all);

        $daily = [];

        foreach (CarbonPeriod::create($filters['from']->copy()->startOfDay(), '1 day', $filters['to']->copy()->startOfDay()) as $date) {
            $key = $date->format('Y-m-d');
            $values = $byDate[$key] ?? [];
            sort($values);

            $daily[] = [
                'date' => $key,
                'count' => count($values),
                'median_seconds' => $this->median($values),
                'p90_seconds' => $this->percentile($values, 0.9),
            ];
        }

        return [
            'summary' => [
                'count' => count($all),
                'median_seconds' => $this->median($all),
                'p90_seconds' => $this->percentile($all, 0.9),
                'avg_seconds' => $all !== [] ? (int) round(array_sum($all) / count($all)) : null,
            ],
            'daily' => $daily,
            'distribution' => $this->responseDistribution($all),
        ];
    }

    /**
     * How many leads reached each stage of a pipeline, based on stage history.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return array{pipeline_id: int|null, pipeline_name: string|null, rows: list<array{stage_id: int, stage: string, position: int, leads: int, conversion_rate: float, step_conversion_rate: float}>}
     */
    public function pipelineFunnel(int $workspaceId, array $filters): array
    {
        $pipeline = $this->resolvePipeline($workspaceId, $filters['pipeline_id']);

        if (! $pipeline) {
            return ['pipeline_id' => null, 'pipeline_name' => null, 'rows' => []];
        }

        $stages = $pipeline->stages()->get(['id', 'name', 'position']);

        if ($stages->isEmpty()) {
            return ['pipeline_id' => (int) $pipeline->id, 'pipeline_name' => $pipeline->name, 'rows' => []];
        }

        $counts = LeadStageHistory::query()
            ->join('leads', 'leads.id', '=', 'lead_stage_history.lead_id')
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->where('leads.workspace_id', $workspaceId)
            ->whereBetween('leads.created_at', [$filters['from'], $filters['to']])
            ->whereIn('lead_stage_history.to_stage_id', $stages->pluck('id')->all())
            ->when($filters['agent_id'], fn (Builder $query, int $id) => $query->where('leads.assigned_user_id', $id))
            ->when($filters['source'], fn (Builder $query, string $source) => $query->whereRaw(self::SOURCE_LABEL_SQL.' = ?', [$source]))
            ->selectRaw('lead_stage_history.to_stage_id as stage_id, COUNT(DISTINCT lead_stage_history.lead_id) as leads')
            ->groupBy('lead_stage_history.to_stage_id')
            ->pluck('leads', 'stage_id');

        $base = (int) ($counts[$stages->first()->id] ?? 0);
        $previous = null;
        $rows = [];

        foreach ($stages as $index => $stage) {
            $leads = (int) ($counts[$stage->id] ?? 0);

            $conversion = $index === 0
                ? ($leads > 0 ? 100.0 : 0.0)
                : ($base > 0 ? round(($leads / $base) * 100, 2) : 0.0);

            $step = $index === 0
                ? ($leads > 0 ? 100.0 : 0.0)
                : (($previous ?? 0) > 0 ? round(($leads / $previous) * 100, 2) : 0.0);

            $rows[] = [
                'stage_id' => (int) $stage->id,
                'stage' => (string) $stage->name,
                'position' => (int) $stage->position,
                'leads' => $leads,
                'conversion_rate' => $conversion,
                'step_conversion_rate' => $step,
            ];

            $previous = $leads;
        }

        return [
            'pipeline_id' => (int) $pipeline->id,
            'pipeline_name' => (string) $pipeline->name,
            'rows' => $rows,
        ];
    }

    /**
     * Average days leads spend in each stage. The last entry of an open lead
     * runs until now; terminal (won/lost) stages have no waiting time.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return list<array{stage_id: int, stage: string, position: int, is_terminal: bool, visits: int, avg_days: float|null}>
     */
    public function stageBottlenecks(int $workspaceId, array $filters): array
    {
        $pipeline = $this->resolvePipeline($workspaceId, $filters['pipeline_id']);

        if (! $pipeline) {
            return [];
        }

        $stages = $pipeline->stages()->get(['id', 'name', 'position', 'is_won', 'is_lost']);

        if ($stages->isEmpty()) {
            return [];
        }

        $stageIds = $stages->pluck('id')->all();
        $stageMap = $stages->keyBy('id');

        $history = LeadStageHistory::query()
            ->join('leads', 'leads.id', '=', 'lead_stage_history.lead_id')
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->where('leads.workspace_id', $workspaceId)
            ->whereBetween('leads.created_at', [$filters['from'], $filters['to']])
            ->whereIn('lead_stage_history.to_stage_id', $stageIds)
            ->when($filters['agent_id'], fn (Builder $query, int $id) => $query->where('leads.assigned_user_id', $id))
            ->when($filters['source'], fn (Builder $query, string $source) => $query->whereRaw(self::SOURCE_LABEL_SQL.' = ?', [$source]))
            ->select('lead_stage_history.lead_id', 'lead_stage_history.to_stage_id', 'lead_stage_history.changed_at')
            ->orderBy('lead_stage_history.lead_id')
            ->orderBy('lead_stage_history.changed_at')
            ->orderBy('lead_stage_history.id')
            ->get();

        /** @var array<int, array{sum: float, visits: int}> $durations */
        $durations = [];

        foreach ($history->groupBy('lead_id') as $entries) {
            $entries = $entries->values();

            foreach ($entries as $index => $entry) {
                $stageId = (int) $entry->to_stage_id;
                $stage = $stageMap->get($stageId);

                if (! $stage) {
                    continue;
                }

                $next = $entries[$index + 1] ?? null;

                if ($next !== null) {
                    $seconds = max(0, (int) $entry->changed_at->diffInSeconds($next->changed_at, false));
                } elseif ($stage->is_won || $stage->is_lost) {
                    continue;
                } else {
                    $seconds = max(0, (int) $entry->changed_at->diffInSeconds(now(), false));
                }

                $durations[$stageId] ??= ['sum' => 0.0, 'visits' => 0];
                $durations[$stageId]['sum'] += $seconds;
                $durations[$stageId]['visits']++;
            }
        }

        return $stages
            ->map(function ($stage) use ($durations): array {
                $entry = $durations[(int) $stage->id] ?? null;

                return [
                    'stage_id' => (int) $stage->id,
                    'stage' => (string) $stage->name,
                    'position' => (int) $stage->position,
                    'is_terminal' => (bool) ($stage->is_won || $stage->is_lost),
                    'visits' => $entry['visits'] ?? 0,
                    'avg_days' => $entry && $entry['visits'] > 0
                        ? round($entry['sum'] / $entry['visits'] / 86400, 2)
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * UTM attribution to revenue: leads, wins and won value per UTM tuple.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return list<array{utm_source: string, utm_medium: string, utm_campaign: string, leads: int, won: int, conversion_rate: float, won_value: float}>
     */
    public function sourceAttribution(int $workspaceId, array $filters): array
    {
        return $this->leadQuery($workspaceId, $filters)
            ->selectRaw("COALESCE(NULLIF(leads.utm_source, ''), 'Direct') as utm_source")
            ->selectRaw("COALESCE(NULLIF(leads.utm_medium, ''), '—') as utm_medium")
            ->selectRaw("COALESCE(NULLIF(leads.utm_campaign, ''), '—') as utm_campaign")
            ->selectRaw('COUNT(*) as leads')
            ->selectRaw('SUM(CASE WHEN '.self::WON_SQL.' THEN 1 ELSE 0 END) as won')
            ->selectRaw('COALESCE(SUM(CASE WHEN '.self::WON_SQL.' THEN leads.deal_value ELSE 0 END), 0) as won_value')
            ->groupByRaw("COALESCE(NULLIF(leads.utm_source, ''), 'Direct')")
            ->groupByRaw("COALESCE(NULLIF(leads.utm_medium, ''), '—')")
            ->groupByRaw("COALESCE(NULLIF(leads.utm_campaign, ''), '—')")
            ->orderByRaw('COALESCE(SUM(CASE WHEN '.self::WON_SQL.' THEN leads.deal_value ELSE 0 END), 0) DESC')
            ->orderByRaw('COUNT(*) DESC')
            ->get()
            ->map(function ($row): array {
                $leads = (int) $row->leads;
                $won = (int) $row->won;

                return [
                    'utm_source' => (string) $row->utm_source,
                    'utm_medium' => (string) $row->utm_medium,
                    'utm_campaign' => (string) $row->utm_campaign,
                    'leads' => $leads,
                    'won' => $won,
                    'conversion_rate' => $leads > 0 ? round(($won / $leads) * 100, 2) : 0.0,
                    'won_value' => round((float) $row->won_value, 2),
                ];
            })
            ->all();
    }

    /**
     * Headline numbers for the KPI cards, based on leads created in the period.
     *
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     * @return array{leads: int, won: int, lost: int, open: int, conversion_rate: float, win_rate: float, won_value: float, pipeline_value: float}
     */
    public function summary(int $workspaceId, array $filters): array
    {
        $row = $this->leadQuery($workspaceId, $filters)
            ->selectRaw('COUNT(*) as leads')
            ->selectRaw('SUM(CASE WHEN '.self::WON_SQL.' THEN 1 ELSE 0 END) as won')
            ->selectRaw('SUM(CASE WHEN '.self::LOST_SQL.' THEN 1 ELSE 0 END) as lost')
            ->selectRaw('COALESCE(SUM(CASE WHEN '.self::WON_SQL.' THEN leads.deal_value ELSE 0 END), 0) as won_value')
            ->selectRaw('COALESCE(SUM(CASE WHEN NOT '.self::WON_SQL.' AND NOT '.self::LOST_SQL.' THEN leads.deal_value ELSE 0 END), 0) as pipeline_value')
            ->first();

        $leads = (int) ($row->leads ?? 0);
        $won = (int) ($row->won ?? 0);
        $lost = (int) ($row->lost ?? 0);
        $closed = $won + $lost;

        return [
            'leads' => $leads,
            'won' => $won,
            'lost' => $lost,
            'open' => max($leads - $won - $lost, 0),
            'conversion_rate' => $leads > 0 ? round(($won / $leads) * 100, 2) : 0.0,
            'win_rate' => $closed > 0 ? round(($won / $closed) * 100, 2) : 0.0,
            'won_value' => round((float) ($row->won_value ?? 0), 2),
            'pipeline_value' => round((float) ($row->pipeline_value ?? 0), 2),
        ];
    }

    /**
     * Source labels available for the source filter dropdown.
     *
     * @return list<string>
     */
    public function sourceOptions(int $workspaceId): array
    {
        return Lead::query()
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->where('leads.workspace_id', $workspaceId)
            ->selectRaw(self::SOURCE_LABEL_SQL.' as label')
            ->distinct()
            ->orderBy('label')
            ->pluck('label')
            ->map(fn ($label): string => (string) $label)
            ->values()
            ->all();
    }

    /**
     * The pipeline a funnel/bottleneck report runs against: the explicit filter
     * or the workspace's default (then first) pipeline.
     */
    private function resolvePipeline(int $workspaceId, ?int $pipelineId): ?LeadPipeline
    {
        if ($pipelineId) {
            return LeadPipeline::where('workspace_id', $workspaceId)->find($pipelineId);
        }

        return LeadPipeline::where('workspace_id', $workspaceId)
            ->orderByDesc('is_default')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  list<int>  $sorted
     */
    private function median(array $sorted): ?int
    {
        $count = count($sorted);

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $sorted[$middle]
            : (int) round(($sorted[$middle - 1] + $sorted[$middle]) / 2);
    }

    /**
     * @param  list<int>  $sorted
     */
    private function percentile(array $sorted, float $percentile): ?int
    {
        $count = count($sorted);

        if ($count === 0) {
            return null;
        }

        $index = (int) max(0, ceil($count * $percentile) - 1);

        return $sorted[min($index, $count - 1)];
    }

    /**
     * @param  list<int>  $seconds
     * @return list<array{bucket: string, count: int}>
     */
    private function responseDistribution(array $seconds): array
    {
        $buckets = [
            'under_5m' => 0,
            '5m_30m' => 0,
            '30m_2h' => 0,
            '2h_24h' => 0,
            'over_24h' => 0,
        ];

        foreach ($seconds as $value) {
            $bucket = match (true) {
                $value < 300 => 'under_5m',
                $value < 1800 => '5m_30m',
                $value < 7200 => '30m_2h',
                $value < 86400 => '2h_24h',
                default => 'over_24h',
            };

            $buckets[$bucket]++;
        }

        return collect($buckets)
            ->map(fn (int $count, string $bucket): array => ['bucket' => $bucket, 'count' => $count])
            ->values()
            ->all();
    }

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     */
    private function leadQuery(int $workspaceId, array $filters): Builder
    {
        $query = Lead::query()
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->where('leads.workspace_id', $workspaceId)
            ->whereBetween('leads.created_at', [$filters['from'], $filters['to']]);

        return $this->applySharedFilters($query, $filters);
    }

    /**
     * @param  array{from: CarbonInterface, to: CarbonInterface, pipeline_id: ?int, agent_id: ?int, source: ?string}  $filters
     */
    private function applySharedFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['pipeline_id'], fn (Builder $query, int $id) => $query->where('leads.pipeline_id', $id))
            ->when($filters['agent_id'], fn (Builder $query, int $id) => $query->where('leads.assigned_user_id', $id))
            ->when($filters['source'], fn (Builder $query, string $source) => $query->whereRaw(self::SOURCE_LABEL_SQL.' = ?', [$source]));
    }

    /**
     * Charts look broken with gaps, so zero-fill the requested range. Very long
     * ranges are left sparse to keep the payload small.
     *
     * @param  array<string, int>  $counts
     * @return list<array{date: string, leads: int}>
     */
    private function fillMissingDays(CarbonInterface $from, CarbonInterface $to, array $counts): array
    {
        $start = Carbon::parse($from)->startOfDay();
        $end = Carbon::parse($to)->startOfDay();

        if ($end->lt($start) || $start->diffInDays($end) > 366) {
            return $this->countsToRows($counts);
        }

        $rows = [];

        for ($cursor = $start->copy(); $cursor->lte($end); $cursor->addDay()) {
            $key = $cursor->toDateString();
            $rows[] = ['date' => $key, 'leads' => $counts[$key] ?? 0];
        }

        return $rows;
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{date: string, leads: int}>
     */
    private function countsToRows(array $counts): array
    {
        /** @var Collection<string, int> $collection */
        $collection = collect($counts);

        return $collection
            ->sortKeys()
            ->map(fn (int $leads, string $date): array => ['date' => $date, 'leads' => $leads])
            ->values()
            ->all();
    }
}
