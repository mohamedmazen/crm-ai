<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\EmbeddingStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Hybrid search: an exact keyword match lifts a chunk's score on top of the
 * cosine similarity without ever lowering a pure semantic score.
 */
class KbHybridSearchTest extends TestCase
{
    use RefreshDatabase;

    private function seedKb(int $workspaceId): AiKnowledgeBase
    {
        return AiKnowledgeBase::create([
            'workspace_id' => $workspaceId,
            'name' => 'Hybrid KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => 3,
            'status' => 'active',
        ]);
    }

    /**
     * @param  list<float>  $embedding
     */
    private function seedChunk(AiKnowledgeBase $kb, string $content, array $embedding): AiKbChunk
    {
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Doc',
            'source_type' => 'text',
            'source_ref' => $content,
            'status' => 'indexed',
        ]);

        return AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => $content,
            'tokens' => 10,
            'embedding' => json_encode($embedding),
        ]);
    }

    #[Test]
    public function a_keyword_match_can_outrank_a_slightly_closer_semantic_chunk(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);

        config(['ai.retrieval.min_score' => 0.0, 'ai.retrieval.keyword_boost' => 0.3]);

        // Cosine 0.406, but every query term appears in the text.
        $lexical = $this->seedChunk($kb, 'REFUND-POLICY-7734 applies to online orders.', [0.4, 0.9, 0.0]);
        // Cosine 0.5 with no matching term.
        $semantic = $this->seedChunk($kb, 'General terms and conditions apply.', [0.5, 0.866, 0.0]);

        $results = app(EmbeddingStore::class)
            ->search($kb->id, [1.0, 0.0, 0.0], 2, 'REFUND-POLICY-7734');

        $this->assertSame($lexical->id, $results[0]['chunk']->id);
        $this->assertGreaterThan($results[1]['score'], $results[0]['score']);
        $this->assertSame($semantic->id, $results[1]['chunk']->id);
    }

    #[Test]
    public function the_boost_can_be_disabled_to_keep_pure_semantic_order(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);

        config(['ai.retrieval.min_score' => 0.0, 'ai.retrieval.keyword_boost' => 0.0]);

        $this->seedChunk($kb, 'REFUND-POLICY-7734 applies to online orders.', [0.4, 0.9, 0.0]);
        $semantic = $this->seedChunk($kb, 'General terms and conditions apply.', [0.5, 0.866, 0.0]);

        $results = app(EmbeddingStore::class)
            ->search($kb->id, [1.0, 0.0, 0.0], 2, 'REFUND-POLICY-7734');

        $this->assertSame($semantic->id, $results[0]['chunk']->id);
    }

    #[Test]
    public function a_keyword_only_match_clears_the_relevance_floor(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);

        config(['ai.retrieval.min_score' => 0.25, 'ai.retrieval.keyword_boost' => 0.3]);

        // Orthogonal to the query vector (cosine 0), but an exact keyword hit.
        $exact = $this->seedChunk($kb, 'SERIAL-99120 warranty terms.', [0.0, 1.0, 0.0]);
        // Semantically weak and no keyword match → below the floor.
        $this->seedChunk($kb, 'Unrelated marketing copy.', [0.1, 0.9, 0.0]);

        $results = app(EmbeddingStore::class)
            ->search($kb->id, [1.0, 0.0, 0.0], 5, 'SERIAL-99120');

        $this->assertCount(1, $results);
        $this->assertSame($exact->id, $results[0]['chunk']->id);
        $this->assertGreaterThanOrEqual(0.25, $results[0]['score']);
    }

    #[Test]
    public function arabic_queries_are_tokenized_and_boosted(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);

        config(['ai.retrieval.min_score' => 0.25, 'ai.retrieval.keyword_boost' => 0.3]);

        $match = $this->seedChunk($kb, 'سياسة الاسترجاع تسمح بالإرجاع خلال ٣٠ يومًا.', [0.0, 1.0, 0.0]);
        $this->seedChunk($kb, 'نص تسويقي لا علاقة له بالموضوع.', [0.1, 0.9, 0.0]);

        $results = app(EmbeddingStore::class)
            ->search($kb->id, [1.0, 0.0, 0.0], 5, 'سياسة الاسترجاع');

        $this->assertCount(1, $results);
        $this->assertSame($match->id, $results[0]['chunk']->id);
    }
}
