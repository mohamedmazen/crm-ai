<?php

namespace App\Modules\Shared\Jobs;

use App\Modules\Shared\Models\GdprErasureRequest;
use App\Modules\Shared\Services\GdprService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Executes one due erasure request. Dispatched by `gdpr:process`; the service
 * re-checks the deadline so a request cancelled between dispatch and run is
 * never actioned.
 */
class PurgeErasureRequestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 300;

    public function __construct(public readonly int $requestId) {}

    public function handle(GdprService $gdpr): void
    {
        $request = GdprErasureRequest::find($this->requestId);

        if (! $request || ! $request->isPending() || $request->due_at->isFuture()) {
            return;
        }

        try {
            $gdpr->execute($request);
        } catch (Throwable $e) {
            Log::error('GDPR erasure request failed.', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
