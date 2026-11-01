<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Jobs\RunAiSdrJob;
use App\Modules\Sales\Models\AiSdrSettings;
use App\Modules\Sales\Services\Ai\AiSdrEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The AI SDR control panel: the kill switch, the guard rails and a manual run.
 *
 * Everything the engine reads lives on {@see AiSdrSettings}, so what a person
 * sees on this page is exactly what the scheduled run will do.
 */
class AiSdrController extends Controller
{
    public function __construct(private readonly AiSdrEngine $engine) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $settings = AiSdrSettings::forWorkspace($wid);

        return Inertia::render('AiSdr/Index', [
            'settings' => [
                'enabled' => $settings->enabled,
                'channel' => $settings->channel,
                'daily_cap' => $settings->daily_cap,
                'per_lead_cap' => $settings->per_lead_cap,
                'min_hours_between' => $settings->min_hours_between,
                'last_run_at' => $settings->last_run_at?->toIso8601String(),
            ],
            'channels' => AiSdrSettings::CHANNELS,
            'usage' => $this->engine->usage($wid),
            'limits' => [
                'daily_cap' => ['min' => 1, 'max' => 200],
                'per_lead_cap' => ['min' => 1, 'max' => 20],
                'min_hours_between' => ['min' => 1, 'max' => 720],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $request->validate([
            'enabled' => ['boolean'],
            'channel' => ['required', Rule::in(AiSdrSettings::CHANNELS)],
            'daily_cap' => ['required', 'integer', 'min:1', 'max:200'],
            'per_lead_cap' => ['required', 'integer', 'min:1', 'max:20'],
            'min_hours_between' => ['required', 'integer', 'min:1', 'max:720'],
        ]);

        $settings = AiSdrSettings::forWorkspace($wid);

        $settings->fill([
            'enabled' => $request->boolean('enabled'),
            'channel' => $data['channel'],
            'daily_cap' => (int) $data['daily_cap'],
            'per_lead_cap' => (int) $data['per_lead_cap'],
            'min_hours_between' => (int) $data['min_hours_between'],
        ])->save();

        return back()->with('success', __('AI SDR settings saved.'));
    }

    public function run(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        if (! AiSdrSettings::forWorkspace($wid)->enabled) {
            return back()->with('error', __('Turn the AI SDR on before running it.'));
        }

        RunAiSdrJob::dispatch($wid);

        return back()->with('success', __('AI SDR queued. Follow-ups appear in each lead’s timeline.'));
    }
}
