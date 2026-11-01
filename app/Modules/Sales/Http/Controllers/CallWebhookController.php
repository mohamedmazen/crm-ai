<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\CallProviderConfig;
use App\Modules\Sales\Services\Calls\CallProviderManager;
use App\Modules\Sales\Services\Calls\CallService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Status / recording callbacks from telephony providers.
 *
 * The URL carries the config id, so the signature is checked against exactly
 * one workspace's credentials — no cross-tenant guessing.
 */
class CallWebhookController extends Controller
{
    public function handle(Request $request, string $provider, int $config, CallService $calls): JsonResponse
    {
        $config = CallProviderConfig::find($config);

        abort_unless($config && $config->provider === $provider, 404);

        $driver = CallProviderManager::forConfig($config);

        abort_unless($driver->verifyWebhook($request), 403);

        $update = $driver->parseWebhook($request);

        if ($update) {
            $calls->recordUpdate($config, $update);
        }

        return response()->json(['ok' => true]);
    }
}
