<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\LeadSource;
use App\Modules\Sales\Services\MetaLeadAdsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MetaLeadAdsWebhookController extends Controller
{
    public function __construct(
        private readonly MetaLeadAdsService $metaLeadAdsService,
    ) {}

    /**
     * Handle Meta Webhook Verification Challenge (GET).
     */
    public function verify(Request $request, string $token): Response
    {
        $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
        $verifyToken = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
        $challenge = $request->query('hub_challenge') ?? $request->query('hub.challenge');

        $source = LeadSource::where('webhook_token', $token)->first();

        if ($source && $verifyToken === $token) {
            return response($challenge, 200, ['Content-Type' => 'text/plain']);
        }

        return response('Forbidden', 403);
    }

    /**
     * Handle Meta Webhook Notification (POST).
     */
    public function receive(Request $request, string $token): JsonResponse
    {
        $leads = $this->metaLeadAdsService->processWebhook($request->all(), $token);

        return response()->json([
            'success' => true,
            'leads_count' => count($leads),
        ]);
    }
}
