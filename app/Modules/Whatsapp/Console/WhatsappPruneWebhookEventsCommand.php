<?php

namespace App\Modules\Whatsapp\Console;

use App\Modules\Whatsapp\Models\WhatsappWebhookEvent;
use Illuminate\Console\Command;

class WhatsappPruneWebhookEventsCommand extends Command
{
    protected $signature = 'whatsapp:prune-webhook-events
                            {--days=30 : Delete processed events older than this many days}';

    protected $description = 'Prune processed WhatsApp webhook events. Failed events are kept for replay.';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));

        $deleted = WhatsappWebhookEvent::where('processing_status', 'processed')
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Pruned {$deleted} processed WhatsApp webhook event(s).");

        return self::SUCCESS;
    }
}
