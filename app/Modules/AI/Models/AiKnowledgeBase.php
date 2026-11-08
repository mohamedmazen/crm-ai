<?php

namespace App\Modules\AI\Models;

use Database\Factories\AiKnowledgeBaseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class AiKnowledgeBase extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return AiKnowledgeBaseFactory::new();
    }

    protected static function boot(): void
    {
        parent::boot();
        static::creating(function (self $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected $table = 'ai_knowledge_bases';

    protected $fillable = ['workspace_id', 'name', 'embedding_model', 'dimensions', 'status'];

    public function documents(): HasMany
    {
        return $this->hasMany(AiKbDocument::class, 'kb_id');
    }

    public function chatbots(): BelongsToMany
    {
        return $this->belongsToMany(
            AiChatbot::class,
            'ai_chatbot_knowledge_base',
            'kb_id',
            'chatbot_id',
        )
            ->withPivot('workspace_id')
            ->withTimestamps();
    }
}
