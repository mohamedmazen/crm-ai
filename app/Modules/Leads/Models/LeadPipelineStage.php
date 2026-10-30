<?php

namespace App\Modules\Leads\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadPipelineStage extends Model
{
    protected $table = 'lead_pipeline_stages';

    /**
     * Seeded for a workspace the first time it opens the board. Ordered as they
     * appear left to right.
     *
     * @var list<array{name: string, color: string, is_won: bool, is_lost: bool, default_probability?: int}>
     */
    public const DEFAULTS = [
        ['name' => 'New', 'color' => 'neutral', 'is_won' => false, 'is_lost' => false, 'default_probability' => 10],
        ['name' => 'Contacted', 'color' => 'blue', 'is_won' => false, 'is_lost' => false, 'default_probability' => 30],
        ['name' => 'Qualified', 'color' => 'violet', 'is_won' => false, 'is_lost' => false, 'default_probability' => 60],
        ['name' => 'Won', 'color' => 'emerald', 'is_won' => true, 'is_lost' => false, 'default_probability' => 100],
        ['name' => 'Lost', 'color' => 'coral', 'is_won' => false, 'is_lost' => true, 'default_probability' => 0],
    ];

    /** Selectable stage colours — mirrored by STAGE_COLORS in the React board. */
    public const COLORS = ['neutral', 'blue', 'violet', 'emerald', 'amber', 'coral'];

    protected $fillable = [
        'workspace_id',
        'pipeline_id',
        'name',
        'color',
        'position',
        'is_won',
        'is_lost',
        'default_probability',
    ];

    protected function casts(): array
    {
        return [
            'is_won' => 'boolean',
            'is_lost' => 'boolean',
            'position' => 'integer',
            'default_probability' => 'integer',
        ];
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(LeadPipeline::class, 'pipeline_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'stage_id');
    }

    public function isTerminal(): bool
    {
        return $this->is_won || $this->is_lost;
    }
}
