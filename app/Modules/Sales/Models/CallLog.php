<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Models\Lead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallLog extends Model
{
    public const DIRECTION_OUTBOUND = 'outbound';

    public const DIRECTION_INBOUND = 'inbound';

    public const STATUS_INITIATED = 'initiated';

    public const STATUS_RINGING = 'ringing';

    public const STATUS_ANSWERED = 'answered';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_NO_ANSWER = 'no_answer';

    public const DIRECTIONS = [self::DIRECTION_OUTBOUND, self::DIRECTION_INBOUND];

    public const STATUSES = [
        self::STATUS_INITIATED,
        self::STATUS_RINGING,
        self::STATUS_ANSWERED,
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_NO_ANSWER,
    ];

    /** Statuses after which the call will not change again. */
    public const TERMINAL_STATUSES = [
        self::STATUS_COMPLETED,
        self::STATUS_FAILED,
        self::STATUS_NO_ANSWER,
    ];

    protected $fillable = [
        'workspace_id',
        'lead_id',
        'user_id',
        'direction',
        'from_number',
        'to_number',
        'duration_secs',
        'status',
        'recording_url',
        'notes',
        'provider',
        'provider_sid',
    ];

    protected function casts(): array
    {
        return [
            'duration_secs' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /** Human duration for the call log table, e.g. 4m 12s. */
    public function durationLabel(): string
    {
        $seconds = max(0, (int) $this->duration_secs);

        if ($seconds === 0) {
            return '—';
        }

        $minutes = intdiv($seconds, 60);
        $remaining = $seconds % 60;

        return $minutes > 0
            ? sprintf('%dm %02ds', $minutes, $remaining)
            : sprintf('%ds', $remaining);
    }
}
