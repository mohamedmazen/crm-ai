<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Leads\Models\Company;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\LeadAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CompanyController extends Controller
{
    public function __construct(
        private readonly LeadAssignmentService $assignmentService,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $query = Company::where('workspace_id', $wid)
            ->with(['owner:id,name,email'])
            ->withCount('leads');

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('domain', 'like', "%{$search}%")
                    ->orWhere('website', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($industry = $request->input('industry')) {
            $query->where('industry', $industry);
        }

        if ($size = $request->input('size')) {
            $query->where('size', $size);
        }

        if ($ownerId = $request->input('owner_id')) {
            $query->where('owner_id', $ownerId);
        }

        $sort = $request->input('sort', 'created_at');
        $direction = $request->input('direction', 'desc');

        if (in_array($sort, ['name', 'created_at', 'leads_count'], true)) {
            $query->orderBy($sort, $direction === 'asc' ? 'asc' : 'desc');
        } else {
            $query->latest();
        }

        $companies = $query->paginate(20)->withQueryString();

        // Calculate total open deal value per company on the current page
        $companyIds = collect($companies->items())->pluck('id')->all();
        $dealTotals = Lead::where('workspace_id', $wid)
            ->whereIn('company_id', $companyIds)
            ->where('status', '!=', 'lost')
            ->selectRaw('company_id, SUM(COALESCE(deal_value, 0)) as total_value')
            ->groupBy('company_id')
            ->pluck('total_value', 'company_id');

        $items = collect($companies->items())->map(function (Company $c) use ($dealTotals) {
            $arr = $c->toArray();
            $arr['total_deals_value'] = (float) ($dealTotals[$c->id] ?? 0);

            return $arr;
        });

        $paginated = $companies->setCollection($items);

        $teamMembers = $this->assignmentService->eligibleUsersFor($wid)->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ])->values();

        return Inertia::render('Leads/Companies/Index', [
            'companies' => $paginated,
            'filters' => [
                'search' => $request->input('search', ''),
                'industry' => $request->input('industry', ''),
                'size' => $request->input('size', ''),
                'owner_id' => $request->input('owner_id', ''),
                'sort' => $sort,
                'direction' => $direction,
            ],
            'industries' => Company::INDUSTRIES,
            'sizes' => Company::SIZES,
            'teamMembers' => $teamMembers,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:256'],
            'domain' => ['nullable', 'string', 'max:256'],
            'industry' => ['nullable', 'string', 'max:128', Rule::in(Company::INDUSTRIES)],
            'size' => ['nullable', 'string', 'max:64', Rule::in(array_keys(Company::SIZES))],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'string', 'max:512'],
            'address' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (empty($data['domain']) && ! empty($data['website'])) {
            $data['domain'] = Company::extractDomain($data['website']);
        }

        Company::create([
            'workspace_id' => $wid,
            ...$data,
        ]);

        return back()->with('success', __('Company created successfully.'));
    }

    public function show(Request $request, Company $company): Response
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $company->workspace_id === $wid, 403);

        $company->load(['owner:id,name,email']);

        $leads = Lead::where('workspace_id', $wid)
            ->where('company_id', $company->id)
            ->with([
                'stage:id,name',
                'pipeline:id,name',
                'assignedUser:id,name,email',
                'tags:id,name,color',
            ])
            ->latest()
            ->get();

        $metrics = [
            'total_leads' => $leads->count(),
            'won_deals_value' => (float) $leads->whereIn('status', ['converted', 'won'])->sum('deal_value'),
            'open_deals_value' => (float) $leads->whereIn('status', ['new', 'contacted', 'qualified', 'open'])->sum('deal_value'),
            'lost_deals_value' => (float) $leads->where('status', 'lost')->sum('deal_value'),
        ];

        $teamMembers = $this->assignmentService->eligibleUsersFor($wid)->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
        ])->values();

        return Inertia::render('Leads/Companies/Show', [
            'company' => $company,
            'leads' => $leads,
            'metrics' => $metrics,
            'industries' => Company::INDUSTRIES,
            'sizes' => Company::SIZES,
            'teamMembers' => $teamMembers,
        ]);
    }

    public function update(Request $request, Company $company): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $company->workspace_id === $wid, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:256'],
            'domain' => ['nullable', 'string', 'max:256'],
            'industry' => ['nullable', 'string', 'max:128', Rule::in(Company::INDUSTRIES)],
            'size' => ['nullable', 'string', 'max:64', Rule::in(array_keys(Company::SIZES))],
            'phone' => ['nullable', 'string', 'max:32'],
            'website' => ['nullable', 'string', 'max:512'],
            'address' => ['nullable', 'string'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        if (empty($data['domain']) && ! empty($data['website'])) {
            $data['domain'] = Company::extractDomain($data['website']);
        }

        $company->update($data);

        return back()->with('success', __('Company updated successfully.'));
    }

    public function destroy(Request $request, Company $company): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $company->workspace_id === $wid, 403);

        $company->delete();

        if ($request->header('X-Inertia-Location') || str_contains($request->header('Referer', ''), (string) $company->id)) {
            return redirect()->route('client.leads.companies.index')->with('success', __('Company deleted successfully.'));
        }

        return back()->with('success', __('Company deleted successfully.'));
    }

    /**
     * Autocomplete JSON search endpoint for quick lookup in modals and forms.
     */
    public function searchJson(Request $request): JsonResponse
    {
        $wid = $this->workspaceId($request);
        $search = trim((string) $request->input('q', ''));

        if ($search === '') {
            $companies = Company::where('workspace_id', $wid)
                ->latest()
                ->limit(10)
                ->get(['id', 'name', 'domain', 'industry', 'website']);
        } else {
            $companies = Company::where('workspace_id', $wid)
                ->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('domain', 'like', "%{$search}%");
                })
                ->limit(15)
                ->get(['id', 'name', 'domain', 'industry', 'website']);
        }

        return response()->json($companies);
    }

    /**
     * Attach a lead to this company.
     */
    public function attachLead(Request $request, Company $company, Lead $lead): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $company->workspace_id === $wid && (int) $lead->workspace_id === $wid, 403);

        $lead->update(['company_id' => $company->id]);

        return back()->with('success', __('Lead attached to company.'));
    }

    /**
     * Detach a lead from this company.
     */
    public function detachLead(Request $request, Company $company, Lead $lead): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $company->workspace_id === $wid && (int) $lead->workspace_id === $wid, 403);

        if ((int) $lead->company_id === (int) $company->id) {
            $lead->update(['company_id' => null]);
        }

        return back()->with('success', __('Lead detached from company.'));
    }
}
