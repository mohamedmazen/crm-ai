<?php

namespace App\Modules\Leads\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\AI\Exceptions\AiFeatureNotIncludedException;
use App\Modules\AI\Exceptions\AiNotConfiguredException;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Services\Ai\LeadCoach;
use App\Modules\Leads\Services\Ai\SmartTagsGenerator;
use App\Modules\Leads\Services\LeadTagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AI assist for a single lead, called from the lead detail modal.
 *
 * Suggestions are returned to the browser and never persisted: the user accepts
 * the ones worth keeping, which then go through the ordinary tag attach path.
 */
class LeadAiController extends Controller
{
    public function __construct(
        private readonly SmartTagsGenerator $tags,
        private readonly LeadCoach $coach,
        private readonly LeadTagService $tagService,
    ) {}

    public function suggestTags(Request $request, Lead $lead): JsonResponse
    {
        $this->authoriseLead($request, $lead);

        try {
            return response()->json(['tags' => $this->tags->suggest($lead)]);
        } catch (AiNotConfiguredException) {
            return response()->json(['error' => 'No AI provider configured. Set one up in AI → Providers.'], 422);
        } catch (AiFeatureNotIncludedException) {
            return response()->json(['error' => 'Your plan does not include AI sales assistance.'], 402);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'AI suggestion failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * What to do next with this lead, grounded in its conversation history.
     */
    public function coach(Request $request, Lead $lead): JsonResponse
    {
        $this->authoriseLead($request, $lead);

        try {
            $advice = $this->coach->advise($lead);
        } catch (AiNotConfiguredException) {
            return response()->json(['error' => 'No AI provider configured. Set one up in AI → Providers.'], 422);
        } catch (AiFeatureNotIncludedException) {
            return response()->json(['error' => 'Your plan does not include AI sales assistance.'], 402);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'AI suggestion failed: '.$e->getMessage()], 500);
        }

        if ($advice === null) {
            return response()->json(['error' => 'The AI could not produce advice for this lead. Please try again.'], 422);
        }

        return response()->json($advice);
    }

    /**
     * Attach accepted tag names. No AI call happens here, so it is not metered.
     */
    public function applyTags(Request $request, Lead $lead): JsonResponse
    {
        $this->authoriseLead($request, $lead);

        $validated = $request->validate([
            'tag_names' => ['required', 'array', 'min:1', 'max:10'],
            'tag_names.*' => ['string', 'max:64'],
        ]);

        $tags = $this->tagService->attach($lead, $validated['tag_names']);

        return response()->json([
            'tags' => $tags->map(fn (LeadTag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'color' => $tag->color,
            ])->values(),
        ]);
    }

    /** Implicit binding resolves the id globally, so the tenant check is manual. */
    private function authoriseLead(Request $request, Lead $lead): void
    {
        $workspaceId = (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);

        abort_unless((int) $lead->workspace_id === $workspaceId, 403);
    }
}
