<?php

namespace App\Modules\Sales\Jobs;

use App\Modules\Sales\Services\Ai\AiSdrEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs one bounded AI SDR pass for a workspace.
 *
 * It is a job rather than inline work so the scheduler (and the "run now"
 * button) never wait on a slow model, and so one workspace's failure cannot
 * hold up the others.
 */
class RunAiSdrJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $workspaceId) {}

    public function handle(AiSdrEngine $engine): void
    {
        $stats = $engine->runWorkspace($this->workspaceId);

        Log::channel('json')->info('ai_sdr.run', [
            'workspace_id' => $this->workspaceId,
            ...$stats,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::channel('json')->warning('ai_sdr.run_failed', [
            'workspace_id' => $this->workspaceId,
            'error' => $e->getMessage(),
        ]);
    }
}
