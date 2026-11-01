<?php

namespace App\Modules\Sales\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The AI SDR's per-workspace configuration.
 *
 * It is deliberately conservative: off until someone turns it on, capped per
 * day and per lead, and quiet for a cooling-off window between nudges. Those
 * guards live here rather than in the engine so the UI and the scheduled run
 * read exactly the same numbers.
 */
class AiSdrSettings extends Model
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNELS = [self::CHANNEL_EMAIL, self::CHANNEL_WHATSAPP];

    public const DEFAULT_DAILY_CAP = 20;

    public const DEFAULT_PER_LEAD_CAP = 3;

    public const DEFAULT_MIN_HOURS_BETWEEN = 48;

    protected $fillable = [
        'workspace_id',
        'enabled',
        'channel',
        'daily_cap',
        'per_lead_cap',
        'min_hours_between',
        'last_run_at',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'daily_cap' => 'integer',
            'per_lead_cap' => 'integer',
            'min_hours_between' => 'integer',
            'last_run_at' => 'datetime',
        ];
    }

    /** The workspace's settings row, created with safe defaults on first access. */
    public static function forWorkspace(int $workspaceId): self
    {
        return self::firstOrCreate(
            ['workspace_id' => $workspaceId],
            [
                'enabled' => false,
                'channel' => self::CHANNEL_EMAIL,
                'daily_cap' => self::DEFAULT_DAILY_CAP,
                'per_lead_cap' => self::DEFAULT_PER_LEAD_CAP,
                'min_hours_between' => self::DEFAULT_MIN_HOURS_BETWEEN,
            ],
        );
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
