<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The knowledge-base analytics page reports index size and the chatbot traffic
 * that actually used this base.
 */
class KbAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function seedKb(int $workspaceId): AiKnowledgeBase
    {
        return AiKnowledgeBase::create([
            'workspace_id' => $workspaceId,
            'name' => 'Analytics KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function it_reports_index_size_and_usage_for_the_last_30_days(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Policy',
            'source_type' => 'text',
            'source_ref' => 'Policy text',
            'status' => 'indexed',
            'tokens' => 20,
            'last_indexed_at' => now(),
        ]);

        AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => 'Policy text',
            'tokens' => 20,
        ]);

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Bot',
            'ai_kb_id' => $kb->id,
            'enabled' => true,
        ]);

        $otherBot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Other bot',
            'enabled' => true,
        ]);

        AiRun::create([
            'workspace_id' => $workspace->id,
            'chatbot_id' => $bot->id,
            'prompt_tokens' => 10,
            'completion_tokens' => 5,
            'status' => 'ok',
        ]);
        AiRun::create([
            'workspace_id' => $workspace->id,
            'chatbot_id' => $bot->id,
            'prompt_tokens' => 20,
            'completion_tokens' => 10,
            'status' => 'ok',
        ]);
        AiRun::create([
            'workspace_id' => $workspace->id,
            'chatbot_id' => $bot->id,
            'prompt_tokens' => 5,
            'completion_tokens' => 0,
            'status' => 'error',
        ]);

        // Traffic from a chatbot that does not use this KB must be excluded.
        AiRun::create([
            'workspace_id' => $workspace->id,
            'chatbot_id' => $otherBot->id,
            'prompt_tokens' => 999,
            'completion_tokens' => 999,
            'status' => 'ok',
        ]);

        $this->actingAs($user)
            ->get(route('client.ai.knowledge-bases.analytics', $kb->uuid))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AI/KnowledgeBases/Analytics')
                ->where('stats.documents', 1)
                ->where('stats.chunks', 1)
                ->where('stats.tokens', 20)
                ->where('stats.chatbots', 1)
                ->where('stats.queries_30d', 3)
                ->where('stats.tokens_30d', 50)
                ->where('stats.error_rate_30d', 33.3)
                ->has('daily', 14)
            );
    }

    #[Test]
    public function chatbots_linked_through_the_multi_base_pivot_are_counted(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);

        // Created through the multi-base UI: the legacy ai_kb_id column is null.
        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Pivot bot',
            'enabled' => true,
        ]);
        $bot->knowledgeBases()->sync([$kb->id]);

        AiRun::create([
            'workspace_id' => $workspace->id,
            'chatbot_id' => $bot->id,
            'prompt_tokens' => 4,
            'completion_tokens' => 2,
            'status' => 'ok',
        ]);

        $this->actingAs($user)
            ->get(route('client.ai.knowledge-bases.analytics', $kb->uuid))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AI/KnowledgeBases/Analytics')
                ->where('stats.chatbots', 1)
                ->where('stats.queries_30d', 1)
                ->where('stats.tokens_30d', 6)
            );
    }

    #[Test]
    public function another_workspace_cannot_read_the_analytics(): void
    {
        ['workspace' => $workspaceA] = $this->createWorkspaceContext();
        ['user' => $userB] = $this->createWorkspaceContext();

        $kb = $this->seedKb($workspaceA->id);

        $this->actingAs($userB)
            ->get(route('client.ai.knowledge-bases.analytics', $kb->uuid))
            ->assertForbidden();
    }
}
