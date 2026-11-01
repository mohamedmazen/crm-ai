<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenceStep extends Model
{
    use HasFactory;

    protected $fillable = [
        'sequence_id',
        'position',
        'channel',
        'delay_hours',
        'subject',
        'body',
        'condition',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'delay_hours' => 'integer',
            'condition' => 'array',
        ];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    /** A WhatsApp step cannot carry an email subject. */
    public function displaySubject(): string
    {
        if ($this->channel === Sequence::CHANNEL_WHATSAPP) {
            return '';
        }

        return (string) ($this->subject ?? '');
    }
}
