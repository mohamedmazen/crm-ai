<?php

namespace App\Modules\Leads\Jobs;

use App\Modules\Leads\Services\DuplicateDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DetectDuplicatesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $workspaceId
    ) {}

    public function handle(DuplicateDetector $detector): void
    {
        $detector->scanWorkspace($this->workspaceId);
    }
}

