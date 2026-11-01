<?php

namespace App\Modules\Leads\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeadAssignmentConfig extends Model
{
    use HasFactory;

    public const MODE_MANUAL = 'manual';

    public const MODE_ROUND_ROBIN = 'round_robin';

    public const MODE_LOAD_BALANCED = 'load_balanced';

    public const MODES = [
        self::MODE_MANUAL,
        self::MODE_ROUND_ROBIN,
        self::MODE_LOAD_BALANCED,
    ];

    protected $table = 'lead_assignment_configs';

    protected $fillable = [
        'pipeline_id',
        'mode',
        'last_assigned_user_id',
    ];

    public function pipeline(): BelongsTo
    {
        return $this->belongsTo(LeadPipeline::class, 'pipeline_id');
    }

    public function lastAssignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_assigned_user_id');
    }
}
