<?php

namespace App\Modules\Leads\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeadCloseReason extends Model
{
    use HasFactory;

    public const TYPE_WON = 'won';

    public const TYPE_LOST = 'lost';

    protected $table = 'lead_close_reasons';

    protected $fillable = [
        'workspace_id',
        'type',
        'name',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'close_reason_id');
    }
}

