<?php

namespace App\Modules\AI\Models;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiRun extends Model
{
    protected $table = 'ai_runs';

    protected $fillable = [
        'workspace_id', 'chatbot_id', 'conversation_id', 'feature',
        'prompt_tokens', 'completion_tokens', 'cost_cents', 'latency_ms', 'model', 'status',
    ];

    protected function casts(): array
    {
        return [
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cost_cents' => 'integer',
            'latency_ms' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** Total tokens billed for the run. */
    public function totalTokens(): int
    {
        return $this->prompt_tokens + $this->completion_tokens;
    }
}
