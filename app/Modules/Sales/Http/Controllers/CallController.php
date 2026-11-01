<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Exceptions\CallNotPossible;
use App\Modules\Sales\Models\CallLog;
use App\Modules\Sales\Models\CallProviderConfig;
use App\Modules\Sales\Services\Calls\CallService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CallController extends Controller
{
    public function __construct(private readonly CallService $calls) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(CallLog::STATUSES)],
            'direction' => ['nullable', Rule::in(CallLog::DIRECTIONS)],
            'lead_id' => ['nullable', 'integer'],
        ]);

        $calls = CallLog::forWorkspace($wid)
            ->with([
                'lead:id,name,phone',
                'user:id,name',
            ])
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['direction'] ?? null), fn ($query) => $query->where('direction', $filters['direction']))
            ->when(filled($filters['lead_id'] ?? null), fn ($query) => $query->where('lead_id', (int) $filters['lead_id']))
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('from_number', 'like', $term)
                        ->orWhere('to_number', 'like', $term)
                        ->orWhereHas('lead', fn ($lead) => $lead->where('name', 'like', $term)->orWhere('phone', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate(25)
            ->withQueryString();

        $config = CallProviderConfig::resolveForWorkspace($wid);

        return Inertia::render('Calls/Index', [
            'calls' => $calls,
            'leads' => Lead::where('workspace_id', $wid)
                ->whereNotNull('phone')
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'phone']),
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
                'direction' => $filters['direction'] ?? '',
                'lead_id' => $filters['lead_id'] ?? null,
            ],
            'stats' => [
                'total' => CallLog::forWorkspace($wid)->count(),
                'completed' => CallLog::forWorkspace($wid)->where('status', CallLog::STATUS_COMPLETED)->count(),
                'today' => CallLog::forWorkspace($wid)->whereDate('created_at', now()->toDateString())->count(),
                'avg_duration' => (int) round(
                    (float) CallLog::forWorkspace($wid)->where('duration_secs', '>', 0)->avg('duration_secs'),
                ),
            ],
            'provider' => [
                'connected' => $config !== null,
                'name' => $config?->provider,
                'caller_id' => $config?->caller_id,
            ],
            'providerFields' => CallProviderConfig::FIELDS,
            'providers' => CallProviderConfig::PROVIDERS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $this->validateCall($request, $wid);
        $lead = filled($data['lead_id'] ?? null)
            ? Lead::where('workspace_id', $wid)->find((int) $data['lead_id'])
            : null;

        $attributes = [
            'lead_id' => $lead?->id,
            'direction' => $data['direction'],
            'status' => $data['status'],
            'duration_secs' => (int) ($data['duration_secs'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'from_number' => $data['from_number'] ?? null,
            'to_number' => $data['to_number'] ?? null,
        ];

        // Fill the number the user did not type from the lead, so the log is
        // readable without re-entering data that already exists.
        if ($lead) {
            if ($attributes['direction'] === CallLog::DIRECTION_OUTBOUND && blank($attributes['to_number'])) {
                $attributes['to_number'] = $lead->phone;
            }

            if ($attributes['direction'] === CallLog::DIRECTION_INBOUND && blank($attributes['from_number'])) {
                $attributes['from_number'] = $lead->phone;
            }
        }

        $this->calls->recordManual($wid, (int) $request->user()->id, $attributes);

        return back()->with('success', __('Call logged.'));
    }

    public function initiate(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $request->validate([
            'lead_id' => ['required', 'integer'],
            'from_number' => ['nullable', 'string', 'max:32'],
        ]);

        $lead = Lead::where('workspace_id', $wid)->find($data['lead_id']);

        if (! $lead) {
            throw ValidationException::withMessages(['lead_id' => __('Lead not found.')]);
        }

        try {
            $call = $this->calls->initiate(
                $wid,
                (int) $request->user()->id,
                $lead,
                $data['from_number'] ?? null,
            );
        } catch (CallNotPossible $e) {
            throw ValidationException::withMessages(['lead_id' => $e->getMessage()]);
        }

        return back()->with('success', __('Calling :number…', ['number' => $call->to_number]));
    }

    public function destroy(Request $request, CallLog $call): RedirectResponse
    {
        abort_unless((int) $call->workspace_id === $this->workspaceId($request), 403);

        $call->delete();

        return back()->with('success', __('Call deleted.'));
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $request->validate([
            'provider' => ['required', Rule::in(CallProviderConfig::PROVIDERS)],
            'caller_id' => ['required', 'string', 'max:32'],
            'credentials' => ['required', 'array'],
        ]);

        $fields = CallProviderConfig::FIELDS[$data['provider']] ?? [];

        foreach ($fields as $field) {
            $value = trim((string) ($data['credentials'][$field['key']] ?? ''));

            if ($field['required'] && $value === '' && ! $this->hasStoredCredential($wid, $data['provider'], $field['key'])) {
                throw ValidationException::withMessages([
                    "credentials.{$field['key']}" => __(':field is required.', ['field' => $field['label']]),
                ]);
            }
        }

        $existing = CallProviderConfig::forWorkspace($wid)
            ->where('provider', $data['provider'])
            ->first();

        $credentials = $existing?->credentials ?? [];

        foreach ($fields as $field) {
            $value = trim((string) ($data['credentials'][$field['key']] ?? ''));

            if ($value !== '') {
                $credentials[$field['key']] = $value;
            }
        }

        DB::transaction(function () use ($wid, $data, $credentials) {
            CallProviderConfig::forWorkspace($wid)->update(['default' => false]);

            CallProviderConfig::updateOrCreate(
                ['workspace_id' => $wid, 'provider' => $data['provider']],
                [
                    'credentials' => $credentials,
                    'caller_id' => $data['caller_id'],
                    'default' => true,
                ],
            );
        });

        return back()->with('success', __('Call provider saved.'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validateCall(Request $request, int $workspaceId): array
    {
        $data = $request->validate([
            'lead_id' => [
                'nullable',
                'integer',
                Rule::exists('leads', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'direction' => ['required', Rule::in(CallLog::DIRECTIONS)],
            'status' => ['required', Rule::in(CallLog::STATUSES)],
            'duration_secs' => ['nullable', 'integer', 'min:0', 'max:86400'],
            'from_number' => ['nullable', 'string', 'max:32'],
            'to_number' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return $data;
    }

    private function hasStoredCredential(int $workspaceId, string $provider, string $key): bool
    {
        $config = CallProviderConfig::forWorkspace($workspaceId)->where('provider', $provider)->first();
        $value = $config?->credentials[$key] ?? null;

        return filled($value);
    }
}
