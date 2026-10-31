<?php

namespace App\Modules\Leads\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One behavioral scoring event for a lead. The row is the source of truth for
 * the behavioral part of a score: the score itself can always be rebuilt by
 * summing these points, so a rescore never has to trust a stored total.
 *
 * created_at is the only timestamp — a scoring event is immutable once written,
 * so there is no updated_at to track.
 */
class LeadScoreEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['lead_id', 'event', 'points', 'reason'];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }
}
