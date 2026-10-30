<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Sales\Models\LeadSource;
use App\Services\FeatureGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class LeadSourceController extends Controller
{
    public function __construct(
        private readonly PipelineManager $pipelineManager,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $user = $request->user();

        // Auto-seed default system channels if empty
        if (LeadSource::where('workspace_id', $wid)->count() === 0) {
            LeadSource::create([
                'workspace_id' => $wid,
                'name' => 'Web Forms',
                'type' => LeadSource::TYPE_FORM,
                'status' => LeadSource::STATUS_ACTIVE,
            ]);

            LeadSource::create([
                'workspace_id' => $wid,
                'name' => 'WhatsApp Chat',
                'type' => LeadSource::TYPE_WHATSAPP,
                'status' => LeadSource::STATUS_ACTIVE,
            ]);

            LeadSource::create([
                'workspace_id' => $wid,
                'name' => 'Default Inbound Webhook',
                'type' => LeadSource::TYPE_WEBHOOK,
                'status' => LeadSource::STATUS_ACTIVE,
            ]);
        }

        $sources = LeadSource::where('workspace_id', $wid)
            ->withCount('leads')
            ->orderBy('id', 'asc')
            ->get();

        $totalSources = $sources->count();
        $activeSources = $sources->where('status', LeadSource::STATUS_ACTIVE)->count();
        $totalLeads = Lead::where('workspace_id', $wid)->count();

        // UTM source distribution
        $utmDistribution = Lead::where('workspace_id', $wid)
            ->whereNotNull('utm_source')
            ->select('utm_source', DB::raw('count(*) as count'))
            ->groupBy('utm_source')
            ->orderByDesc('count')
            ->limit(6)
            ->get();

        $pipelines = $this->pipelineManager->pipelinesFor($wid)->load('stages');
        $limit = FeatureGate::forUser($user)->limit('lead_sources');

        return Inertia::render('LeadSources/Index', [
            'sources' => $sources,
            'stats' => [
                'total_sources' => $totalSources,
                'active_sources' => $activeSources,
                'total_leads' => $totalLeads,
            ],
            'utm_distribution' => $utmDistribution,
            'pipelines' => $pipelines,
            'limits' => [
                'current' => $totalSources,
                'max' => $limit,
            ],
            'tracker_url' => url('/tracker.js'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $user = $request->user();

        $limit = FeatureGate::forUser($user)->limit('lead_sources');
        if ($limit !== null && LeadSource::where('workspace_id', $wid)->count() >= $limit) {
            return back()->with('error', __('lead_sources.limit_reached', ['limit' => $limit]));
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'type' => ['required', 'string', Rule::in([
                LeadSource::TYPE_FORM,
                LeadSource::TYPE_WEBHOOK,
                LeadSource::TYPE_API,
                LeadSource::TYPE_WHATSAPP,
                LeadSource::TYPE_META_ADS,
                LeadSource::TYPE_IMAP,
                LeadSource::TYPE_MANUAL,
                LeadSource::TYPE_SCRAPER,
                LeadSource::TYPE_IMPORT,
            ])],
            'pipeline_id' => ['nullable', 'integer'],
            'stage_id' => ['nullable', 'integer'],
        ]);

        $config = [];
        if (! empty($validated['pipeline_id'])) {
            $config['pipeline_id'] = (int) $validated['pipeline_id'];
        }
        if (! empty($validated['stage_id'])) {
            $config['stage_id'] = (int) $validated['stage_id'];
        }

        LeadSource::create([
            'workspace_id' => $wid,
            'name' => $validated['name'],
            'type' => $validated['type'],
            'status' => LeadSource::STATUS_ACTIVE,
            'config' => ! empty($config) ? $config : null,
        ]);

        return back()->with('success', __('lead_sources.created_success'));
    }

    public function update(Request $request, LeadSource $leadSource): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        if ($leadSource->workspace_id !== $wid) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'status' => ['required', 'string', Rule::in([
                LeadSource::STATUS_ACTIVE,
                LeadSource::STATUS_ERROR,
                LeadSource::STATUS_INACTIVE,
            ])],
            'pipeline_id' => ['nullable', 'integer'],
            'stage_id' => ['nullable', 'integer'],
        ]);

        $config = $leadSource->config ?? [];
        if (array_key_exists('pipeline_id', $validated)) {
            $config['pipeline_id'] = $validated['pipeline_id'] ? (int) $validated['pipeline_id'] : null;
        }
        if (array_key_exists('stage_id', $validated)) {
            $config['stage_id'] = $validated['stage_id'] ? (int) $validated['stage_id'] : null;
        }

        $leadSource->update([
            'name' => $validated['name'],
            'status' => $validated['status'],
            'config' => $config,
        ]);

        return back()->with('success', __('lead_sources.updated_success'));
    }

    public function regenerateToken(Request $request, LeadSource $leadSource): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        if ($leadSource->workspace_id !== $wid) {
            abort(403);
        }

        $leadSource->regenerateWebhookToken();

        return back()->with('success', __('lead_sources.token_regenerated'));
    }

    public function destroy(Request $request, LeadSource $leadSource): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        if ($leadSource->workspace_id !== $wid) {
            abort(403);
        }

        $leadSource->delete();

        return back()->with('success', __('lead_sources.deleted_success'));
    }
}

