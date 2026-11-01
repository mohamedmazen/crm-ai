<?php

namespace App\Modules\Sales\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Sequence extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP];

    public const SOURCE_USER = 'user';

    /** The hidden sequence the AI SDR sends through; never shown in the builder. */
    public const SOURCE_AI_SDR = 'ai_sdr';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'name',
        'source',
        'status',
        'stop_on_reply',
        'stop_on_won',
        'respect_hours',
    ];

    protected function casts(): array
    {
        return [
            'stop_on_reply' => 'boolean',
            'stop_on_won' => 'boolean',
            'respect_hours' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Sequence $sequence) {
            if (empty($sequence->uuid)) {
                $sequence->uuid = (string) Str::uuid();
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SequenceStep::class)->orderBy('position')->orderBy('id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(SequenceEnrollment::class)->latest();
    }

    /**
     * The workspace's user-built sequences. The AI SDR's system sequence is a
     * delivery detail, not something a person manages, so it is excluded here
     * and therefore also from the plan's sequence count.
     */
    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query
            ->where('workspace_id', $workspaceId)
            ->where('source', self::SOURCE_USER);
    }

    public function scopeSystem(Builder $query, int $workspaceId): Builder
    {
        return $query
            ->where('workspace_id', $workspaceId)
            ->where('source', self::SOURCE_AI_SDR);
    }

    public function isSystem(): bool
    {
        return $this->source === self::SOURCE_AI_SDR;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function totalSteps(): int
    {
        return $this->steps()->count();
    }
}
