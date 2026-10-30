<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Leads\Jobs\RescoreWorkspaceLeadsJob;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Models\LeadCloseReason;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadScoringConfig;
use App\Modules\Leads\Services\ActivityRecorder;
use App\Modules\Leads\Services\LeadAssignmentService;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LeadPipelineController extends Controller
{
    /**
     * Cards fetched per column. Beyond this the column reports its true total and
     * the tenant narrows with search or the band/due filters.
     */
    private const CARDS_PER_COLUMN = 50;

    public function __construct(
        private readonly PipelineManager $pipeline,
        private readonly ActivityRecorder $activity,
        private readonly LeadAssignmentService $assignment,
    ) {}

    /** Board query with the tenant's filters applied. */
    private function filtered(int $wid, array $filters, ?int $pipelineId = null): Builder
    {
        $query = Lead::where('workspace_id', $wid);

        if ($pipelineId) {
            $query->where(function ($q) use ($pipelineId) {
                $q->where('pipeline_id', $pipelineId)
                    ->orWhereNull('pipeline_id');
            });
        }

        if (! empty($filters['assigned'])) {
            if ($filters['assigned'] === 'me') {
                $query->where('assigned_user_id', Auth::id());
            } elseif ($filters['assigned'] === 'unassigned') {
                $query->whereNull('assigned_user_id');
            } elseif (is_numeric($filters['assigned'])) {
                $query->where('assigned_user_id', (int) $filters['assigned']);
            }
        }

        if (! empty($filters['band'])) {
            $query->where('score_band', $filters['band']);
        }

        if (! empty($filters['due'])) {
            $query->followUpDue();
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('category', 'like', $term));
        }

        return $query;
    }

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    /** Every stage/lead lookup goes through this, so a cross-workspace id 404s. */
    private function stage(Request $request, int $stageId): LeadPipelineStage
    {
        return LeadPipelineStage::where('workspace_id', $this->workspaceId($request))
            ->findOrFail($stageId);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $pipelines = $this->pipeline->pipelinesFor($wid);

        $requestedPipelineId = $request->integer('pipeline_id') ?: null;
        $activePipeline = $requestedPipelineId
            ? $pipelines->firstWhere('id', $requestedPipelineId)
            : ($pipelines->firstWhere('is_default', true) ?? $pipelines->first());

        if (! $activePipeline) {
            $activePipeline = $this->pipeline->defaultPipelineFor($wid);
            $pipelines = new Collection([$activePipeline]);
        }

        $stages = $this->pipeline->stagesFor($wid, $activePipeline->id);

        $filters = $request->validate([
            'band' => ['nullable', Rule::in(['hot', 'warm', 'cold'])],
            'search' => ['nullable', 'string', 'max:128'],
            'due' => ['nullable', 'boolean'],
            'lead' => ['nullable', 'integer'],
            'pipeline_id' => ['nullable', 'integer'],
            'assigned' => ['nullable', 'string', 'max:32'],
        ]);

        $matching = fn () => $this->filtered($wid, $filters, $activePipeline->id);

        $totals = $matching()
            ->selectRaw('stage_id, count(*) as aggregate, sum(coalesce(deal_value, 0)) as total_value, sum(coalesce(deal_value, 0) * coalesce(probability, 0) / 100) as weighted_value')
            ->groupBy('stage_id')
            ->get()
            ->keyBy('stage_id');

        $boardTotal = (int) $matching()->count();
        $hotTotal = (int) $matching()->where('score_band', 'hot')->count();
        $boardDealValue = (float) $matching()->sum('deal_value');
        $boardWeightedValue = (float) $matching()->selectRaw('sum(coalesce(deal_value, 0) * coalesce(probability, 0) / 100) as val')->value('val');

        $closeReasons = LeadCloseReason::where('workspace_id', $wid)
            ->orderBy('sort_order')
            ->get();

        return Inertia::render('Leads/Pipeline', [
            'pipelines' => $pipelines->map(fn (LeadPipeline $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'is_default' => (bool) $p->is_default,
            ]),
            'activePipelineId' => $activePipeline->id,
            'boardDealValue' => $boardDealValue,
            'boardWeightedValue' => $boardWeightedValue,
            'closeReasons' => $closeReasons->map(fn (LeadCloseReason $r) => [
                'id' => $r->id,
                'type' => $r->type,
                'name' => $r->name,
            ]),
            'stages' => $stages->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'color' => $s->color,
                'position' => $s->position,
                'is_won' => (bool) $s->is_won,
                'is_lost' => (bool) $s->is_lost,
                'default_probability' => $s->default_probability,
                'total' => (int) ($totals[$s->id]->aggregate ?? 0),
                'totalValue' => (float) ($totals[$s->id]->total_value ?? 0),
                'weightedValue' => (float) ($totals[$s->id]->weighted_value ?? 0),
                'leads' => $matching()
                    ->where('stage_id', $s->id)
                    ->with(['contact:id,lead_id', 'assignedUser:id,name'])
                    ->boardOrdered()
                    ->limit(self::CARDS_PER_COLUMN)
                    ->get(),
            ]),
            'teamMembers' => $this->assignment->eligibleUsersFor($wid)->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ])->values(),
            'perColumn' => self::CARDS_PER_COLUMN,
            'boardTotal' => $boardTotal,
            'hotTotal' => $hotTotal,
            'colors' => LeadPipelineStage::COLORS,
            'filters' => $filters,
            'scoring' => $this->scoringPayload($wid),
            'activityTypes' => LeadActivity::MANUAL_TYPES,
            'callOutcomes' => LeadActivity::OUTCOMES,
            'leadDetail' => $this->leadDetail($wid, $request->integer('lead') ?: null),
        ]);
    }

    /** One lead plus its follow-up history, for the detail modal. */
    private function leadDetail(int $wid, ?int $leadId): ?array
    {
        if (! $leadId) {
            return null;
        }

        $lead = Lead::where('workspace_id', $wid)
            ->with([
                'stage:id,name',
                'contact:id,lead_id',
                'closeReason:id,name,type',
                'pipeline:id,name',
                'assignedUser:id,name,email',
                'activities.user:id,name',
                'stageHistory.fromStage:id,name',
                'stageHistory.toStage:id,name',
                'stageHistory.user:id,name',
            ])
            ->find($leadId);

        if (! $lead) {
            return null;
        }

        return [
            'lead' => Arr::only($lead->toArray(), [
                'id', 'name', 'phone', 'email', 'website', 'address', 'category',
                'rating', 'review_count', 'score', 'score_band', 'score_breakdown',
                'stage_id', 'pipeline_id', 'status', 'deal_value', 'currency',
                'probability', 'expected_close_date', 'won_at', 'lost_at',
                'close_reason_id', 'assigned_user_id', 'assigned_at', 'next_follow_up_at', 'last_activity_at',
            ]) + [
                'contact' => $lead->contact ? ['id' => $lead->contact->id] : null,
                'pipeline' => $lead->pipeline ? ['id' => $lead->pipeline->id, 'name' => $lead->pipeline->name] : null,
                'assigned_user' => $lead->assignedUser ? ['id' => $lead->assignedUser->id, 'name' => $lead->assignedUser->name, 'email' => $lead->assignedUser->email] : null,
                'close_reason' => $lead->closeReason ? ['id' => $lead->closeReason->id, 'name' => $lead->closeReason->name, 'type' => $lead->closeReason->type] : null,
            ],
            'activities' => $lead->activities->map(fn (LeadActivity $a) => Arr::only($a->toArray(), [
                'id', 'type', 'body', 'meta', 'occurred_at',
            ]) + [
                'is_system' => $a->isSystem(),
                'user' => $a->user ? ['id' => $a->user->id, 'name' => $a->user->name] : null,
            ])->values(),
            'stage_history' => $lead->stageHistory->map(fn ($h) => [
                'id' => $h->id,
                'from_stage' => $h->fromStage?->name,
                'to_stage' => $h->toStage?->name,
                'user' => $h->user?->name,
                'changed_at' => $h->changed_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** Log a follow-up activity. */
    public function storeActivity(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless((int) $lead->workspace_id === $this->workspaceId($request), 403);

        $data = $request->validate([
            'type' => ['required', Rule::in(LeadActivity::MANUAL_TYPES)],
            'body' => ['nullable', 'string', 'max:2000'],
            'outcome' => ['nullable', Rule::in(LeadActivity::OUTCOMES)],
            'next_follow_up_at' => ['nullable', 'date'],
            'clear_follow_up' => ['nullable', 'boolean'],
            'stage_id' => ['nullable', 'integer'],
        ]);

        if ($data['type'] === 'note' && blank($data['body'] ?? null)) {
            throw ValidationException::withMessages([
                'body' => __('Add a note before saving.'),
            ]);
        }

        $meta = array_filter(['outcome' => $data['outcome'] ?? null]);

        $this->activity->record($lead, $data['type'], $data['body'] ?? null, $meta);

        if (! empty($data['clear_follow_up'])) {
            $lead->update(['next_follow_up_at' => null]);
        } elseif (! empty($data['next_follow_up_at'])) {
            $lead->update(['next_follow_up_at' => $data['next_follow_up_at']]);
        }

        if (! empty($data['stage_id']) && (int) $data['stage_id'] !== (int) $lead->stage_id) {
            $stage = $this->stage($request, (int) $data['stage_id']);
            $this->pipeline->moveLead($lead, $stage, 0, $request->user()->id);
        }

        return back()->with('success', __('Activity logged.'));
    }

    public function move(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless((int) $lead->workspace_id === $this->workspaceId($request), 403);

        $data = $request->validate([
            'stage_id' => ['required', 'integer'],
            'position' => ['required', 'integer', 'min:0'],
            'close_reason_id' => ['nullable', 'integer'],
        ]);

        $this->pipeline->moveLead(
            $lead,
            $this->stage($request, $data['stage_id']),
            $data['position'],
            $request->user()->id,
            $data['close_reason_id'] ?? null
        );

        return back();
    }

    public function updateDeal(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless((int) $lead->workspace_id === $this->workspaceId($request), 403);

        $data = $request->validate([
            'deal_value' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:8'],
            'probability' => ['nullable', 'integer', 'min:0', 'max:100'],
            'expected_close_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['new', 'contacted', 'qualified', 'converted', 'lost'])],
            'close_reason_id' => ['nullable', 'integer'],
        ]);

        $lead->update($data);

        return back()->with('success', __('Deal updated.'));
    }

    public function assign(Request $request, Lead $lead): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $lead->workspace_id === $wid, 403);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer'],
        ]);

        $user = ! empty($data['user_id']) ? User::findOrFail($data['user_id']) : null;

        if ($user) {
            $eligible = $this->assignment->eligibleUsersFor($wid);
            if (! $eligible->contains('id', $user->id)) {
                abort(403, 'User does not belong to this workspace or organization.');
            }
        }

        $this->assignment->assign($lead, $user, $request->user());

        return back()->with(
            'success',
            $user ? __('Lead assigned to :name.', ['name' => $user->name]) : __('Lead unassigned.')
        );
    }

    public function storeStage(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $request->validate([
            'pipeline_id' => ['nullable', 'integer'],
            'name' => ['required', 'string', 'max:128'],
            'color' => ['required', Rule::in(LeadPipelineStage::COLORS)],
            'is_won' => ['boolean'],
            'is_lost' => ['boolean'],
            'default_probability' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $pipelineId = $data['pipeline_id'] ?? $this->pipeline->defaultPipelineFor($wid)->id;

        LeadPipelineStage::create([
            'workspace_id' => $wid,
            'pipeline_id' => $pipelineId,
            'name' => $data['name'],
            'color' => $data['color'],
            'is_won' => $data['is_won'] ?? false,
            'is_lost' => $data['is_lost'] ?? false,
            'default_probability' => $data['default_probability'] ?? null,
            'position' => $this->pipeline->nextStagePosition($wid, $pipelineId),
        ]);

        return back()->with('success', __('Stage created.'));
    }

    public function updateStage(Request $request, int $stage): RedirectResponse
    {
        $model = $this->stage($request, $stage);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'color' => ['required', Rule::in(LeadPipelineStage::COLORS)],
            'is_won' => ['boolean'],
            'is_lost' => ['boolean'],
            'default_probability' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $model->update($data);

        return back()->with('success', __('Stage updated.'));
    }

    public function destroyStage(Request $request, int $stage): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $model = $this->stage($request, $stage);

        $fallback = LeadPipelineStage::where('workspace_id', $wid)
            ->where('pipeline_id', $model->pipeline_id)
            ->where('id', '!=', $model->id)
            ->orderBy('position')
            ->first();

        if (! $fallback) {
            throw ValidationException::withMessages([
                'stage' => __('You cannot delete your only stage in this pipeline.'),
            ]);
        }

        $this->pipeline->deleteStage($model, $fallback);

        return back()->with('success', __('Stage deleted. Its leads moved to :stage.', ['stage' => $fallback->name]));
    }

    public function reorderStages(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        $owned = LeadPipelineStage::where('workspace_id', $wid)
            ->whereIn('id', $data['ids'])
            ->pluck('id')
            ->all();

        $ordered = array_values(array_filter($data['ids'], fn ($id) => in_array($id, $owned, true)));

        $this->pipeline->reorderStages($wid, $ordered);

        return back();
    }

    public function rescore(Request $request): RedirectResponse
    {
        RescoreWorkspaceLeadsJob::dispatch($this->workspaceId($request))->onQueue('leads');

        return back()->with('success', __('Rescoring your leads — the board will update shortly.'));
    }

    public function updateScoring(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $rules = [
            'thresholds.hot' => ['required', 'integer', 'min:1', 'max:100'],
            'thresholds.warm' => ['required', 'integer', 'min:0', 'max:100'],
        ];

        foreach (array_keys(LeadScoringConfig::DEFAULT_WEIGHTS) as $rule) {
            $rules["weights.{$rule}"] = ['required', 'integer', 'min:0', 'max:100'];
        }

        $data = $request->validate($rules);

        if ($data['thresholds']['warm'] > $data['thresholds']['hot']) {
            throw ValidationException::withMessages([
                'thresholds.warm' => __('The warm threshold cannot be higher than the hot threshold.'),
            ]);
        }

        if (array_sum($data['weights']) === 0) {
            throw ValidationException::withMessages([
                'weights' => __('At least one rule must have a weight above zero.'),
            ]);
        }

        $config = LeadScoringConfig::forWorkspace($wid);
        $config->fill(['workspace_id' => $wid, 'weights' => $data['weights'], 'thresholds' => $data['thresholds']]);
        $config->save();

        RescoreWorkspaceLeadsJob::dispatch($wid)->onQueue('leads');

        return back()->with('success', __('Scoring settings saved. Your leads are being rescored.'));
    }

    /** @return array{weights: array<string, int>, thresholds: array<string, int>, rules: list<string>} */
    private function scoringPayload(int $wid): array
    {
        $config = LeadScoringConfig::forWorkspace($wid);

        return [
            'weights' => $config->effectiveWeights(),
            'thresholds' => $config->effectiveThresholds(),
            'rules' => array_keys(LeadScoringConfig::DEFAULT_WEIGHTS),
        ];
    }
}
