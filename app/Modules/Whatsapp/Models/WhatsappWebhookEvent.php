<?php

namespace App\Modules\Whatsapp\Models;

use Illuminate\Database\Eloquent\Model;

class WhatsappWebhookEvent extends Model
{
    protected $table = 'whatsapp_webhook_events';

    protected $fillable = [
        'workspace_id',
        'waba_id',
        'phone_number_id',
        'event_key',
        'object',
        'event_type',
        'payload',
        'processing_status',
        'attempts',
        'error_message',
        'received_at',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function markProcessed(): void
    {
        $this->forceFill([
            'processing_status' => 'processed',
            'processed_at' => now(),
            'error_message' => null,
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill([
            'processing_status' => 'failed',
            'error_message' => mb_substr($message, 0, 2000),
        ])->save();
    }
}
