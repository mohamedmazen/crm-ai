<?php

namespace App\Modules\Whatsapp\Console;

use App\Modules\Whatsapp\Models\WhatsappWebhookEvent;
use App\Modules\Whatsapp\Services\WhatsappWebhookEventStore;
use Illuminate\Console\Command;

class WhatsappReplayWebhookEventsCommand extends Command
{
    protected $signature = 'whatsapp:replay-webhook-events
                            {--id=* : Replay these event ids}
                            {--status=failed : Only replay events with this processing status}
                            {--limit=100 : Maximum number of events to replay}';

    protected $description = 'Re-queue stored WhatsApp webhook events (dead-letter retry).';

    public function handle(WhatsappWebhookEventStore $store): int
    {
        $ids = array_values(array_filter((array) $this->option('id')));

        $events = WhatsappWebhookEvent::query()
            ->when($ids !== [], fn ($query) => $query->whereIn('id', $ids))
            ->when($ids === [], fn ($query) => $query->where('processing_status', (string) $this->option('status')))
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($events as $event) {
            $store->replay($event);
        }

        $this->info("Replayed {$events->count()} WhatsApp webhook event(s).");

        return self::SUCCESS;
    }
}
