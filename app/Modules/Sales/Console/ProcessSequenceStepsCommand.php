<?php

namespace App\Modules\Sales\Console;

use App\Modules\Sales\Services\SequenceEngine;
use Illuminate\Console\Command;

/**
 * Queues every sequence step whose delay has elapsed.
 *
 * Runs every minute. The engine claims each enrollment as it queues it, so a
 * slow worker or a missed tick cannot send the same step twice.
 */
class ProcessSequenceStepsCommand extends Command
{
    protected $signature = 'sequences:process {--limit=200 : Maximum enrollments to queue per run}';

    protected $description = 'Queue sequence steps that are due to be sent.';

    public function handle(SequenceEngine $engine): int
    {
        $queued = $engine->processDue(max(1, (int) $this->option('limit')));

        $this->info("Queued {$queued} sequence step(s).");

        return self::SUCCESS;
    }
}
