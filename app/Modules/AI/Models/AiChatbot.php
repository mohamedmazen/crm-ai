<?php

namespace App\Modules\AI\Models;

use App\Listeners\AutoReplyListener;
use Database\Factories\AiChatbotFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * An AI chatbot bound to a workspace.
 *
 * Routing is per channel account, not per chatbot: {@see AutoReplyListener}
 * reads `channel_account.meta_json['ai_chatbot_id']` to pick the bot that answers
 * an inbound message. The `channels` column is stored for API consumers but does
 * not route traffic — assign a bot under Inbox → Setup for it to reply.
 */
class AiChatbot extends Model
{
    use HasFactory;

    protected static function newFactory()
    {
        return AiChatbotFactory::new();
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

    protected $table = 'ai_chatbots';

    protected $fillable = ['workspace_id', 'name', 'ai_kb_id', 'system_prompt', 'tone', 'max_context_chunks', 'fallback_reply', 'channels', 'enabled'];

    protected function casts(): array
    {
        return [
            'channels' => 'array',
            'enabled' => 'boolean',
            'max_context_chunks' => 'integer',
        ];
    }

    public function knowledgeBase(): BelongsTo
    {
        return $this->belongsTo(AiKnowledgeBase::class, 'ai_kb_id');
    }

    /**
     * Knowledge bases the bot searches. Supports several bases per chatbot;
     * retrieval merges and re-ranks the hits across all of them.
     */
    public function knowledgeBases(): BelongsToMany
    {
        return $this->belongsToMany(
            AiKnowledgeBase::class,
            'ai_chatbot_knowledge_base',
            'chatbot_id',
            'kb_id',
        )->withTimestamps();
    }

    /**
     * Effective knowledge bases for retrieval.
     *
     * The pivot is authoritative; `ai_kb_id` is kept as a fallback for
     * chatbots created before multi-base support (and for API clients that
     * still send a single id).
     *
     * @return Collection<int, AiKnowledgeBase>
     */
    public function resolvedKnowledgeBases(): Collection
    {
        $this->loadMissing(['knowledgeBases', 'knowledgeBase']);

        if ($this->knowledgeBases->isNotEmpty()) {
            return $this->knowledgeBases;
        }

        return $this->knowledgeBase !== null
            ? new Collection([$this->knowledgeBase])
            : new Collection;
    }

    public function runs()
    {
        return $this->hasMany(AiRun::class, 'chatbot_id');
    }
}
