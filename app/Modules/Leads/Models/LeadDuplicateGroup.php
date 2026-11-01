<?php

namespace App\Modules\Leads\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LeadDuplicateGroup extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_MERGED = 'merged';

    public const STATUS_DISMISSED = 'dismissed';

    protected $fillable = [
        'workspace_id',
        'match_type',
        'match_value',
        'status',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'lead_duplicate_group_members', 'group_id', 'lead_id');
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
