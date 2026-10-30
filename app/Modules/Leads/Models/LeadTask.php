<?php

namespace App\Modules\Leads\Models;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadTask extends Model
{
    use HasFactory;

    public const TYPES = ['call', 'email', 'whatsapp', 'meeting', 'todo'];

    public const PRIORITIES = ['low', 'medium', 'high'];

    protected $table = 'lead_tasks';

    protected $fillable = [
        'workspace_id',
        'lead_id',
        'assigned_user_id',
        'created_by',
        'title',
        'description',
        'type',
        'priority',
        'due_at',
        'completed_at',
        'reminded_at',
    ];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
            'reminded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (LeadTask $task) {
            $task->syncLeadNextFollowUp();
        });

        static::deleted(function (LeadTask $task) {
            $task->syncLeadNextFollowUp();
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isCompleted() && $this->due_at && $this->due_at->isPast();
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->pending()->where('due_at', '<', now());
    }

    public function scopeDueToday(Builder $query): Builder
    {
        return $query->pending()->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->pending()->where('due_at', '>', now()->endOfDay());
    }

    public function scopeAssignedTo(Builder $query, int $userId): Builder
    {
        return $query->where('assigned_user_id', $userId);
    }

    public function syncLeadNextFollowUp(): void
    {
        if (! $this->lead_id) {
            return;
        }

        $next = static::where('lead_id', $this->lead_id)
            ->whereNull('completed_at')
            ->whereNotNull('due_at')
            ->orderBy('due_at')
            ->value('due_at');

        Lead::where('id', $this->lead_id)->update(['next_follow_up_at' => $next]);
    }
}
