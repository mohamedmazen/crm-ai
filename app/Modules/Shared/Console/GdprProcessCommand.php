<?php

namespace App\Modules\Shared\Console;

use App\Modules\Shared\Jobs\PurgeErasureRequestJob;
use App\Modules\Shared\Services\GdprService;
use Illuminate\Console\Command;

class GdprProcessCommand extends Command
{
    protected $signature = 'gdpr:process {--retention-days= : Override the export-archive retention window}';

    protected $description = 'Dispatch due erasure requests and apply data-retention cleanup.';

    public function handle(GdprService $gdpr): int
    {
        $dispatched = 0;

        foreach ($gdpr->dueRequests() as $request) {
            PurgeErasureRequestJob::dispatch($request->id);
            $dispatched++;
        }

        $retention = $this->option('retention-days');
        $deleted = $gdpr->applyExportRetention($retention !== null ? (int) $retention : null);

        $this->info("Dispatched {$dispatched} erasure request(s); deleted {$deleted} export archive(s).");

        return self::SUCCESS;
    }
}
