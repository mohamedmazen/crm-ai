<?php

namespace App\Modules\Sales\Models;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Leads\Models\LeadPipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Form extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    public const ASSIGNMENT_NONE = 'none';

    public const ASSIGNMENT_SPECIFIC = 'specific';

    public const ASSIGNMENT_ROUND_ROBIN = 'round_robin';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'name',
        'slug',
        'status',
        'pipeline_id',
        'target_stage_id',
        'assignment_mode',
        'assigned_user_id',
        'auto_tags',
        'thank_you_type',
        'thank_you_value',
        'spam_protection',
        'settings',
        'views_count',
        'submissions_count',
    ];

    protected function casts(): array
    {
        return [
            'auto_tags' => 'array',
            'settings' => 'array',
            'views_count' => 'integer',
            'submissions_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Form $form) {
            if (empty($form->uuid)) {
                $form->uuid = (string) Str::uuid();
            }
            if (empty($form->slug)) {
                $form->slug = Str::slug($form->name).'-'.Str::random(6);
            }
        });
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function fields(): HasMany
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order')->orderBy('id');
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(FormSubmission::class)->latest();
    }

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(LeadPipeline::class, 'pipeline_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(LeadPipelineStage::class, 'target_stage_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function scopeForWorkspace(Builder $query, int $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function conversionRate(): float
    {
        if ($this->views_count <= 0) {
            return 0.0;
        }

        return round(($this->submissions_count / $this->views_count) * 100, 1);
    }

    public function publicUrl(): string
    {
        return url('/f/'.$this->slug);
    }

    public function embedCode(): string
    {
        return sprintf(
            '<iframe src="%s" width="100%%" height="550" frameborder="0" style="border:none;border-radius:12px;overflow:hidden;" loading="lazy"></iframe>',
            $this->publicUrl()
        );
    }
}
