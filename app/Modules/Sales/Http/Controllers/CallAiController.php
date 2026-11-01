<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Exceptions\AiFeatureNotIncludedException;
use App\Modules\AI\Exceptions\AiNotConfiguredException;
use App\Modules\Sales\Models\CallLog;
use App\Modules\Sales\Services\Ai\CallSummariser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI assist for a logged call, called from the call log and the lead modal.
 *
 * The transcript may arrive with the request (a rep pasting it after the call)
 * or already be stored on the row. Generating a summary is the metered action;
 * storing a transcript is not.
 */
class CallAiController extends Controller
{
    public function __construct(private readonly CallSummariser $summariser) {}

    public function summarise(Request $request, CallLog $call): JsonResponse
    {
        $this->authoriseCall($request, $call);

        $validated = $request->validate([
            'transcript' => ['nullable', 'string', 'max:20000'],
        ]);

        // Only touch the column when the client sent it, so a plain "regenerate"
        // never clears a transcript that is already stored.
        if (array_key_exists('transcript', $validated)) {
            $call->transcript = $validated['transcript'];
            $call->save();
        }

        if (! $call->hasTranscript()) {
            return response()->json(['error' => 'Add a transcript before generating a summary.'], 422);
        }

        try {
            $result = $this->summariser->summarise($call);
        } catch (AiNotConfiguredException) {
            return response()->json(['error' => 'No AI provider configured. Set one up in AI → Providers.'], 422);
        } catch (AiFeatureNotIncludedException) {
            return response()->json(['error' => 'Your plan does not include AI sales assistance.'], 402);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'AI summary failed: '.$e->getMessage()], 500);
        }

        if ($result === null) {
            return response()->json(['error' => 'The AI could not summarise this call. Please try again.'], 422);
        }

        return response()->json($result);
    }

    /** Implicit binding resolves the id globally, so the tenant check is manual. */
    private function authoriseCall(Request $request, CallLog $call): void
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        abort_unless((int) $call->workspace_id === $workspaceId, 403);
    }
}
