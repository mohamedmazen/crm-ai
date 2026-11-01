<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SequenceEvent extends Model
{
    use HasFactory;

    public const TYPE_SENT = 'sent';

    public const TYPE_OPEN = 'open';

    public const TYPE_CLICK = 'click';

    public const TYPE_REPLY = 'reply';

    public const TYPE_BOUNCE = 'bounce';

    public const TYPE_UNSUBSCRIBE = 'unsubscribe';

    public $timestamps = false;

    protected $fillable = [
        'enrollment_id',
        'step_position',
        'type',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'step_position' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(SequenceEnrollment::class, 'enrollment_id');
    }
}
