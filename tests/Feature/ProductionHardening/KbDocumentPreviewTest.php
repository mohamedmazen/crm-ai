<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Models\AiRun;
use App\Modules\AI\Services\ChatbotRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Document preview (extracted chunks) and "which passages were used" tracking.
 */
class KbDocumentPreviewTest extends TestCase
{
    use RefreshDatabase;

    private function seedKb(int $workspaceId, string $name = 'Preview KB', int $dimensions = 3): AiKnowledgeBase
    {
        return AiKnowledgeBase::create([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => $dimensions,
            'status' => 'active',
        ]);
    }

    private function seedDocument(AiKnowledgeBase $kb): AiKbDocument
    {
        return AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Refund Policy',
            'source_type' => 'text',
            'source_ref' => 'Our refund policy is 30 days.',
            'status' => 'indexed',
            'tokens' => 12,
            'last_indexed_at' => now(),
        ]);
    }

    private function seedChunk(AiKbDocument $doc, string $content, int $ord, ?string $heading = null): AiKbChunk
    {
        return AiKbChunk::create([
            'kb_id' => $doc->kb_id,
            'document_id' => $doc->id,
            'ord' => $ord,
            'content' => $content,
            'tokens' => 10,
            'embedding' => json_encode([1.0, 0.0, 0.0]),
            'meta' => $heading !== null ? ['heading' => $heading] : null,
        ]);
    }

    #[Test]
    public function the_document_preview_lists_chunks_and_how_often_they_were_used(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);
        $doc = $this->seedDocument($kb);

        $first = $this->seedChunk($doc, 'Refunds close after 30 days.', 0, 'Refunds');
        $this->seedChunk($doc, 'Shipping takes five days.', 1, 'Shipping');

        // Two recent runs used the first chunk, one used the second.
        AiRun::create([
            'workspace_id' => $workspace->id,
            'context_chunk_ids' => [$first->id],
            'status' => 'ok',
        ]);
        AiRun::create([
            'workspace_id' => $workspace->id,
            'context_chunk_ids' => [$first->id],
            'status' => 'ok',
        ]);

        // Older than the reporting window: must not be counted.
        $old = AiRun::create([
            'workspace_id' => $workspace->id,
            'context_chunk_ids' => [$first->id],
            'status' => 'ok',
        ]);
        $old->forceFill(['created_at' => now()->subDays(40)])->save();

        $this->actingAs($user)
            ->get(route('client.ai.knowledge-bases.documents.show', [$kb->uuid, $doc->uuid]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AI/KnowledgeBases/Document')
                ->where('document.title', 'Refund Policy')
                ->where('document.chunk_count', 2)
                ->has('chunks.data', 2)
                ->where('chunks.data.0.heading', 'Refunds')
                ->where('chunks.data.0.used_30d', 2)
                ->where('chunks.data.0.embedded', true)
                ->where('chunks.data.1.heading', 'Shipping')
                ->where('chunks.data.1.used_30d', 0)
            );
    }

    #[Test]
    public function the_analytics_page_lists_the_most_used_passages(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);
        $doc = $this->seedDocument($kb);

        $popular = $this->seedChunk($doc, 'POPULAR-MARKER refund window.', 0, 'Refunds');
        $rare = $this->seedChunk($doc, 'RARE-MARKER warranty note.', 1);

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Bot',
            'ai_kb_id' => $kb->id,
            'enabled' => true,
        ]);

        foreach ([$popular->id, $popular->id, $rare->id] as $chunkId) {
            AiRun::create([
                'workspace_id' => $workspace->id,
                'chatbot_id' => $bot->id,
                'context_chunk_ids' => [$chunkId],
                'status' => 'ok',
            ]);
        }

        $this->actingAs($user)
            ->get(route('client.ai.knowledge-bases.analytics', $kb->uuid))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AI/KnowledgeBases/Analytics')
                ->has('topChunks', 2)
                ->where('topChunks.0.id', $popular->id)
                ->where('topChunks.0.uses', 2)
                ->where('topChunks.0.document', 'Refund Policy')
                ->where('topChunks.1.id', $rare->id)
                ->where('topChunks.1.uses', 1)
            );
    }

    #[Test]
    public function a_document_from_another_base_or_workspace_cannot_be_previewed(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);
        $otherKb = $this->seedKb($workspace->id, 'Other KB');
        $foreignDoc = $this->seedDocument($otherKb);

        $this->actingAs($user)
            ->get(route('client.ai.knowledge-bases.documents.show', [$kb->uuid, $foreignDoc->uuid]))
            ->assertNotFound();

        ['user' => $otherUser] = $this->createWorkspaceContext();

        $this->actingAs($otherUser)
            ->get(route('client.ai.knowledge-bases.documents.show', [$kb->uuid, $foreignDoc->uuid]))
            ->assertForbidden();
    }

    #[Test]
    public function the_chatbot_ledger_records_which_chunks_were_injected(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);
        $doc = $this->seedDocument($kb);
        $chunk = $this->seedChunk($doc, 'Our refund policy is 30 days.', 0);

        AiProviderConfig::create([
            'workspace_id' => $workspace->id,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [1.0, 0.0, 0.0]]],
            ], 200),
            'api.openai.com/v1/chat/completions' => Http::response([
                'choices' => [['message' => ['content' => 'Within 30 days.']]],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                'model' => 'gpt-4o-mini',
            ], 200),
        ]);

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Bot',
            'ai_kb_id' => $kb->id,
            'max_context_chunks' => 3,
            'enabled' => true,
        ]);

        app(ChatbotRunner::class)->runForApi($bot, 'What is the refund policy?', $workspace->id);

        $run = AiRun::where('workspace_id', $workspace->id)
            ->where('chatbot_id', $bot->id)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame([$chunk->id], $run->context_chunk_ids);
    }
}
