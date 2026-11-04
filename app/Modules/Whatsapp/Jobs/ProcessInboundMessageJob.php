<?php

namespace App\Modules\Whatsapp\Jobs;

use App\Modules\Whatsapp\Models\WhatsappWebhookEvent;
use App\Modules\Whatsapp\Services\WhatsappDriver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessInboundMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry up to 5 times on transient failures (network, DB lock, etc.). */
    public int $tries = 5;

    /** Hard timeout per attempt — covers upstream API calls and DB writes. */
    public int $timeout = 120;

    /** Max unhandled exceptions before the job is marked failed without retrying. */
    public int $maxExceptions = 3;

    /** Exponential back-off in seconds: 30 s, 60 s, 120 s, 240 s, 300 s. */
    public function backoff(): array
    {
        return [30, 60, 120, 240, 300];
    }

    public function __construct(
        private readonly array $payload,
        private readonly string $verifyToken,
        private readonly ?int $webhookEventId = null,
    ) {}

    public function handle(WhatsappDriver $driver): void
    {
        $event = $this->webhookEvent();
        $event?->increment('attempts');

        try {
            $driver->processWebhookPayload($this->payload, $this->verifyToken);
        } catch (\Throwable $e) {
            $event?->markFailed($e->getMessage());

            throw $e;
        }

        $event?->markProcessed();
    }

    public function failed(\Throwable $e): void
    {
        $this->webhookEvent()?->markFailed($e->getMessage());

        Log::error('ProcessInboundMessageJob failed permanently', [
            'error'        => $e->getMessage(),
            'verify_token' => substr($this->verifyToken, 0, 8).'…',
            'entry_ids'    => collect($this->payload['entry'] ?? [])->pluck('id')->all(),
            'event_id'     => $this->webhookEventId,
        ]);
    }

    private function webhookEvent(): ?WhatsappWebhookEvent
    {
        return $this->webhookEventId
            ? WhatsappWebhookEvent::find($this->webhookEventId)
            : null;
    }
}
