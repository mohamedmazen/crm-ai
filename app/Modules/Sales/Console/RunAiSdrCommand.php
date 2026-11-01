<?php

namespace App\Modules\Sales\Console;

use App\Modules\Sales\Jobs\RunAiSdrJob;
use App\Modules\Sales\Models\AiSdrSettings;
use Illuminate\Console\Command;

/**
 * Queues an AI SDR pass for every workspace that switched it on.
 *
 * The plan gate is re-checked inside the engine, so this command does not need
 * to know which workspaces are entitled — a downgraded workspace simply does
 * nothing when its job runs.
 */
class RunAiSdrCommand extends Command
{
    protected $signature = 'ai-sdr:run {--workspace= : Only this workspace id}';

    protected $description = 'Queue the AI SDR follow-up pass for eligible workspaces.';

    public function handle(): int
    {
        $query = AiSdrSettings::query()->where('enabled', true);

        if ($workspace = $this->option('workspace')) {
            $query->where('workspace_id', (int) $workspace);
        }

        $workspaceIds = $query->pluck('workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            RunAiSdrJob::dispatch((int) $workspaceId);
        }

        $this->info("Queued {$workspaceIds->count()} AI SDR run(s).");

        return self::SUCCESS;
    }
}
