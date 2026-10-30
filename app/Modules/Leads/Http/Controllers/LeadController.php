<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Jobs\ScrapeLeadsJob;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadScrapeJob;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Services\ActivityRecorder;
use App\Modules\Leads\Services\LeadAssignmentService;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Shared\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LeadController extends Controller
{
    public function __construct(
        private readonly ActivityRecorder $activity,
        private readonly PipelineManager $pipelineManager,
        private readonly LeadAssignmentService $assignment,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:128'],
            'pipeline_id' => ['nullable', 'integer'],
            'stage_id' => ['nullable', 'integer'],
            'band' => ['nullable', 'string', 'in:hot,warm,cold'],
            'assigned' => ['nullable', 'string', 'max:32'],
            'tag_id' => ['nullable', 'integer'],
        ]);

        $query = Lead::where('workspace_id', $wid)
            ->with([
                'tags:id,name,color',
                'assignedUser:id,name',
                'pipeline:id,name',
                'stage:id,name,color',
                'contact:id,lead_id',
            ]);

        if (! empty($filters['pipeline_id'])) {
            $query->where('pipeline_id', $filters['pipeline_id']);
        }

        if (! empty($filters['stage_id'])) {
            $query->where('stage_id', $filters['stage_id']);
        }

        if (! empty($filters['band'])) {
            $query->where('score_band', $filters['band']);
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

        if (! empty($filters['tag_id'])) {
            $query->whereHas('tags', fn ($tq) => $tq->where('lead_tags.id', (int) $filters['tag_id']));
        }

        if (! empty($filters['search'])) {
            $term = '%'.$filters['search'].'%';
            $query->where(fn ($q) => $q->where('name', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('category', 'like', $term));
        }

        $leads = $query->latest('id')->paginate(50)->withQueryString();

        $scrapeJobs = LeadScrapeJob::where('workspace_id', $wid)
            ->latest()->limit(5)->get();

        $pipelines = $this->pipelineManager->pipelinesFor($wid);
        $stages = LeadPipelineStage::where('workspace_id', $wid)->orderBy('position')->get();
        $tags = LeadTag::where('workspace_id', $wid)->orderBy('name')->get();
        $teamMembers = $this->assignment->eligibleUsersFor($wid)->map(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ])->values();

        return Inertia::render('Leads/Index', [
            'leads' => $leads,
            'scrapeJobs' => $scrapeJobs,
            'filters' => $filters,
            'pipelines' => $pipelines,
            'stages' => $stages,
            'tags' => $tags,
            'teamMembers' => $teamMembers,
        ]);
    }

    public function scrape(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $validated = $request->validate([
            'keyword' => ['required', 'string', 'max:256'],
            'location' => ['required', 'string', 'max:256'],
            'radius_meters' => ['nullable', 'integer', 'min:100', 'max:50000'],
        ]);

        $job = LeadScrapeJob::create(array_merge($validated, [
            'workspace_id' => $wid,
            'radius_meters' => $validated['radius_meters'] ?? 5000,
            'status' => 'pending',
        ]));

        ScrapeLeadsJob::dispatch($job->id)->onQueue('leads');

        return back()->with('success', 'Scrape job started. Results will appear shortly.');
    }

    public function pushToContacts(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $ids = $request->validate(['ids' => ['required', 'array']])['ids'];

        $leads = Lead::where('workspace_id', $wid)->whereIn('id', $ids)->where('pushed_to_contacts', false)->get();

        foreach ($leads as $lead) {
            if (! $lead->phone && ! $lead->email) {
                continue;
            }

            $nameParts = explode(' ', $lead->name ?? '', 2);
            $contact = Contact::firstOrCreate(
                ['workspace_id' => $wid, 'phone_e164' => $lead->phone],
                [
                    'first_name' => $nameParts[0] ?? null,
                    'last_name' => $nameParts[1] ?? null,
                    'email' => $lead->email,
                    'source' => 'lead_scraper',
                    'lead_id' => $lead->id,
                ]
            );

            // An existing contact predates this lead and so has no lead_id. Without
            // the link, pipeline automations have no contact to run against.
            if (! $contact->lead_id) {
                $contact->update(['lead_id' => $lead->id]);
            }

            $lead->update(['pushed_to_contacts' => true]);
            $this->activity->pushedToContacts($lead, (int) $contact->id);
        }

        return back()->with('success', count($leads).' lead(s) pushed to contacts.');
    }

    public function destroy(Request $request, Lead $lead): RedirectResponse
    {
        abort_unless((int) $lead->workspace_id === $this->workspaceId($request), 403);
        $lead->delete();

        return back()->with('success', 'Lead deleted.');
    }
}
