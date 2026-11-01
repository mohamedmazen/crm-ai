<?php

namespace App\Http\Controllers\Client\Settings;

use App\Http\Controllers\Controller;
use App\Models\ClientSetting;
use App\Models\SystemSetting;
use App\Models\User;
use App\Modules\Shared\Models\GdprErasureRequest;
use App\Modules\Shared\Services\GdprService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Workspace-facing privacy controls: erasure requests, the DPA download and
 * export retention. Every action is restricted to client administrators.
 */
class GdprController extends Controller
{
    public function __construct(private readonly GdprService $gdpr) {}

    public function index(Request $request): Response
    {
        $user = $this->administrator($request);
        $workspaceId = $this->workspaceId($user);

        $requests = GdprErasureRequest::forWorkspace($workspaceId)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (GdprErasureRequest $request) => [
                'id' => $request->id,
                'email' => $request->email,
                'status' => $request->status,
                'due_at' => $request->due_at?->toIso8601String(),
                'days_remaining' => $request->isPending() ? $request->daysRemaining() : 0,
                'completed_at' => $request->completed_at?->toIso8601String(),
                'cancelled_at' => $request->cancelled_at?->toIso8601String(),
                'notes' => $request->notes,
            ]);

        return Inertia::render('client/Settings/Gdpr', [
            'requests' => $requests,
            'retentionDays' => (int) ClientSetting::get(
                $user->client_id,
                'export_retention_days',
                (int) SystemSetting::get('privacy.export_retention_days', GdprService::DEFAULT_EXPORT_RETENTION_DAYS),
            ),
            'graceDays' => GdprService::ERASURE_GRACE_DAYS,
            'clientName' => $user->client?->name,
        ]);
    }

    public function storeErasure(Request $request): RedirectResponse
    {
        $user = $this->administrator($request);

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->gdpr->requestErasure(
            $this->workspaceId($user),
            $validated['email'],
            (int) $user->id,
            $validated['notes'] ?? null,
        );

        return back()->with('success', __('Erasure request created. Data will be deleted after :days days unless cancelled.', [
            'days' => GdprService::ERASURE_GRACE_DAYS,
        ]));
    }

    public function cancelErasure(Request $request, GdprErasureRequest $erasureRequest): RedirectResponse
    {
        $user = $this->administrator($request);

        abort_unless((int) $erasureRequest->workspace_id === $this->workspaceId($user), 404);

        if (! $this->gdpr->cancel($erasureRequest)) {
            return back()->with('error', __('This request can no longer be cancelled.'));
        }

        return back()->with('success', __('Erasure request cancelled.'));
    }

    public function downloadDpa(Request $request): HttpResponse
    {
        $user = $this->administrator($request);
        $client = $user->client;

        abort_unless($client, 404);

        return $this->gdpr->dpa($client)->download('dpa-'.now()->format('Y-m-d').'.pdf');
    }

    public function updateRetention(Request $request): RedirectResponse
    {
        $user = $this->administrator($request);

        $validated = $request->validate([
            'retention_days' => ['required', 'integer', 'min:0', 'max:365'],
        ]);

        ClientSetting::set((int) $user->client_id, 'export_retention_days', (string) $validated['retention_days']);

        return back()->with('success', __('Retention policy saved.'));
    }

    private function administrator(Request $request): User
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->client_id && $user->isClientAdministrator(),
            403,
            __('Only client administrators can manage privacy settings.'),
        );

        return $user;
    }

    private function workspaceId(User $user): int
    {
        return (int) ($user->current_workspace_id ?? $user->workspace_id);
    }
}
