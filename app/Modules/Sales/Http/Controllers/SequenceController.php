<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Sequence;
use App\Modules\Sales\Models\SequenceEnrollment;
use App\Modules\Sales\Models\SequenceEvent;
use App\Modules\Sales\Models\SequenceStep;
use App\Modules\Sales\Services\SequenceEngine;
use App\Services\FeatureGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class SequenceController extends Controller
{
    public function __construct(private readonly SequenceEngine $engine) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $sequences = Sequence::forWorkspace($wid)
            ->withCount([
                'steps as steps_count',
                'enrollments as enrollments_count',
                'enrollments as active_enrollments_count' => fn ($query) => $query->where('status', SequenceEnrollment::STATUS_ACTIVE),
            ])
            ->latest()
            ->paginate(15);

        $limit = FeatureGate::forUser($request->user())->limit('sequences');

        return Inertia::render('Sequences/Index', [
            'sequences' => $sequences,
            'usage' => [
                'current' => Sequence::forWorkspace($wid)->count(),
                'limit' => $limit,
                'unlimited' => $limit === null,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $limit = FeatureGate::forUser($request->user())->limit('sequences');

        if ($limit !== null && Sequence::forWorkspace($wid)->count() >= $limit) {
            throw ValidationException::withMessages([
                'name' => __('Your plan allows up to :limit sequence(s). Please upgrade to create more.', ['limit' => $limit]),
            ]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
        ]);

        $sequence = Sequence::create([
            'workspace_id' => $wid,
            'name' => $data['name'],
            'status' => Sequence::STATUS_DRAFT,
            'stop_on_reply' => true,
            'stop_on_won' => true,
            'respect_hours' => true,
        ]);

        return redirect()
            ->route('client.sales.sequences.builder', $sequence->id)
            ->with('success', __('Sequence created successfully.'));
    }

    public function builder(Request $request, Sequence $sequence): Response
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $sequence->workspace_id === $wid, 403);

        $sequence->load(['steps', 'enrollments.lead:id,name,email,phone,status']);

        $leadIds = $sequence->enrollments->pluck('lead_id')->all();

        $leads = Lead::where('workspace_id', $wid)
            ->whereNotIn('id', $leadIds)
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'name', 'email', 'phone', 'status']);

        return Inertia::render('Sequences/Builder', [
            'sequence' => [
                ...$sequence->toArray(),
                'steps' => $sequence->steps,
            ],
            'enrollments' => $sequence->enrollments,
            'leads' => $leads,
            'channels' => Sequence::CHANNELS,
            'stats' => $this->stats($sequence),
        ]);
    }

    public function updateBuilder(Request $request, Sequence $sequence): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $sequence->workspace_id === $wid, 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'status' => ['required', Rule::in([Sequence::STATUS_DRAFT, Sequence::STATUS_ACTIVE, Sequence::STATUS_PAUSED])],
            'stop_on_reply' => ['boolean'],
            'stop_on_won' => ['boolean'],
            'respect_hours' => ['boolean'],
            'steps' => ['required', 'array', 'min:1'],
            'steps.*.id' => ['nullable', 'integer'],
            'steps.*.channel' => ['required', Rule::in(Sequence::CHANNELS)],
            'steps.*.delay_hours' => ['required', 'integer', 'min:0', 'max:8760'],
            'steps.*.subject' => ['nullable', 'string', 'max:256'],
            'steps.*.body' => ['required', 'string'],
            'steps.*.condition' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($sequence, $data) {
            $sequence->update([
                'name' => $data['name'],
                'status' => $data['status'],
                'stop_on_reply' => $data['stop_on_reply'] ?? $sequence->stop_on_reply,
                'stop_on_won' => $data['stop_on_won'] ?? $sequence->stop_on_won,
                'respect_hours' => $data['respect_hours'] ?? $sequence->respect_hours,
            ]);

            // Park existing positions out of the way so re-ordering between rows
            // cannot collide with the (sequence_id, position) unique index.
            SequenceStep::where('sequence_id', $sequence->id)
                ->update(['position' => DB::raw('position + 1000')]);

            $keep = [];

            foreach (array_values($data['steps']) as $index => $stepData) {
                $attributes = [
                    'position' => $index + 1,
                    'channel' => $stepData['channel'],
                    'delay_hours' => (int) $stepData['delay_hours'],
                    'subject' => $stepData['channel'] === Sequence::CHANNEL_EMAIL
                        ? ($stepData['subject'] ?? null)
                        : null,
                    'body' => $stepData['body'],
                    'condition' => $stepData['condition'] ?? null,
                ];

                $stepId = $stepData['id'] ?? null;

                if ($stepId && ($step = SequenceStep::where('sequence_id', $sequence->id)->find($stepId))) {
                    $step->update($attributes);
                    $keep[] = $step->id;

                    continue;
                }

                $keep[] = $sequence->steps()->create($attributes)->id;
            }

            SequenceStep::where('sequence_id', $sequence->id)
                ->whereNotIn('id', $keep)
                ->delete();
        });

        return back()->with('success', __('Sequence saved successfully.'));
    }

    public function updateStatus(Request $request, Sequence $sequence): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $sequence->workspace_id === $wid, 403);

        $data = $request->validate([
            'status' => ['required', Rule::in([Sequence::STATUS_DRAFT, Sequence::STATUS_ACTIVE, Sequence::STATUS_PAUSED])],
        ]);

        if ($data['status'] === Sequence::STATUS_ACTIVE && $sequence->steps()->count() === 0) {
            throw ValidationException::withMessages([
                'status' => __('Add at least one step before activating the sequence.'),
            ]);
        }

        $sequence->update(['status' => $data['status']]);

        return back()->with('success', __('Sequence status updated.'));
    }

    public function enroll(Request $request, Sequence $sequence): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $sequence->workspace_id === $wid, 403);

        if ($sequence->status !== Sequence::STATUS_ACTIVE) {
            throw ValidationException::withMessages([
                'leads' => __('Activate the sequence before enrolling leads.'),
            ]);
        }

        $data = $request->validate([
            'lead_ids' => ['required', 'array', 'min:1'],
            'lead_ids.*' => ['integer'],
        ]);

        $leads = Lead::where('workspace_id', $wid)
            ->whereIn('id', $data['lead_ids'])
            ->get();

        foreach ($leads as $lead) {
            $this->engine->enroll($sequence, $lead, 'manual');
        }

        return back()->with('success', __(':count lead(s) enrolled.', ['count' => $leads->count()]));
    }

    public function destroy(Request $request, Sequence $sequence): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $sequence->workspace_id === $wid, 403);

        $sequence->delete();

        return redirect()
            ->route('client.sales.sequences.index')
            ->with('success', __('Sequence deleted successfully.'));
    }

    /**
     * @return array{sent: int, replies: int, active: int, completed: int, stopped: int}
     */
    private function stats(Sequence $sequence): array
    {
        $events = SequenceEvent::whereHas(
            'enrollment',
            fn ($query) => $query->where('sequence_id', $sequence->id),
        );

        return [
            'sent' => (clone $events)->where('type', SequenceEvent::TYPE_SENT)->count(),
            'replies' => (clone $events)->where('type', SequenceEvent::TYPE_REPLY)->count(),
            'active' => $sequence->enrollments()->where('status', SequenceEnrollment::STATUS_ACTIVE)->count(),
            'completed' => $sequence->enrollments()->where('status', SequenceEnrollment::STATUS_COMPLETED)->count(),
            'stopped' => $sequence->enrollments()->where('status', SequenceEnrollment::STATUS_STOPPED)->count(),
        ];
    }
}
