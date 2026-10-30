<?php

namespace App\Modules\Leads\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LeadPipeline extends Model
{
    use HasFactory;

    protected $table = 'lead_pipelines';

    protected $fillable = [
        'workspace_id',
        'name',
        'is_default',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(LeadPipelineStage::class, 'pipeline_id')->orderBy('position')->orderBy('id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'pipeline_id');
    }

    public function assignmentConfig(): HasOne
    {
        return $this->hasOne(LeadAssignmentConfig::class, 'pipeline_id');
    }
}

