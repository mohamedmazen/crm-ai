<?php

namespace App\Modules\Leads\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class LeadTag extends Model
{
    use HasFactory;

    protected $table = 'lead_tags';

    protected $fillable = [
        'workspace_id',
        'name',
        'color',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function leads(): BelongsToMany
    {
        return $this->belongsToMany(Lead::class, 'lead_tag_pivot', 'tag_id', 'lead_id');
    }
}

