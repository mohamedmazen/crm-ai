<?php

namespace App\Modules\Sales\Models;

use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SequenceEnrollment extends Model
{
    use HasFactory;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_STOPPED = 'stopped';

    public const STATUS_BOUNCED = 'bounced';

    protected $fillable = [
        'sequence_id',
        'lead_id',
        'current_step',
        'status',
        'stop_reason',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'next_run_at' => 'datetime',
        ];
    }

    public function sequence(): BelongsTo
    {
        return $this->belongsTo(Sequence::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SequenceEvent::class, 'enrollment_id')->latest('created_at')->latest('id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->active()
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', now());
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** The next step to send, or null when the sequence has been exhausted. */
    public function nextStep(): ?SequenceStep
    {
        $sequence = $this->sequence;

        if (! $sequence) {
            return null;
        }

        // The engine eager-loads sequence.steps; reuse that collection instead of
        // re-querying on every check (nextStep() is called twice per send).
        if ($sequence->relationLoaded('steps')) {
            return $sequence->steps
                ->where('position', '>', $this->current_step)
                ->first();
        }

        return $sequence->steps()
            ->where('position', '>', $this->current_step)
            ->orderBy('position')
            ->first();
    }
}
