<?php

namespace Tests\Feature\ProductionHardening;

use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * A chatbot can search several knowledge bases at once; retrieval merges and
 * re-ranks the hits instead of only reading the first base.
 */
class KbMultiKnowledgeBaseTest extends TestCase
{
    use RefreshDatabase;

    private function seedKb(int $workspaceId, string $name, int $dimensions = 3): AiKnowledgeBase
    {
        return AiKnowledgeBase::create([
            'workspace_id' => $workspaceId,
            'name' => $name,
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => $dimensions,
            'status' => 'active',
        ]);
    }

    /**
     * @param  list<float>  $embedding
     */
    private function seedChunk(AiKnowledgeBase $kb, string $title, string $content, array $embedding): AiKbChunk
    {
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => $title,
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

    private function configureOpenAi(int $workspaceId): void
    {
        AiProviderConfig::create([
            'workspace_id' => $workspaceId,
            'provider' => 'openai',
            'credentials' => ['api_key' => 'sk-test'],
            'default_model_chat' => 'gpt-4o-mini',
            'default_model_embed' => 'text-embedding-3-small',
            'enabled' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $capture
     */
    private function fakeOpenAi(array &$capture): void
    {
        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [1.0, 0.0, 0.0]]],
            ], 200),
            'api.openai.com/v1/chat/completions' => function ($request) use (&$capture) {
                $body = json_decode($request->body(), true);
                $capture['system'] = collect($body['messages'] ?? [])
                    ->firstWhere('role', 'system')['content'] ?? '';

                return Http::response([
                    'choices' => [['message' => ['content' => 'OK']]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);
    }

    #[Test]
    public function retrieval_merges_and_ranks_hits_from_every_attached_base(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->configureOpenAi($workspace->id);

        $billing = $this->seedKb($workspace->id, 'Billing');
        $shipping = $this->seedKb($workspace->id, 'Shipping');

        $this->seedChunk($billing, 'Refunds', 'REFUND-MARKER: refunds are open for 30 days.', [1.0, 0.1, 0.0]);
        $this->seedChunk($shipping, 'Delivery', 'SHIPPING-MARKER: delivery takes 5 days.', [1.0, 0.6, 0.0]);

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Multi bot',
            'system_prompt' => 'You are helpful.',
            'max_context_chunks' => 3,
            'enabled' => true,
        ]);
        $bot->knowledgeBases()->syncWithPivotValues([$billing->id, $shipping->id], ['workspace_id' => $workspace->id]);

        $capture = [];
        $this->fakeOpenAi($capture);

        app(ChatbotRunner::class)->runForApi($bot, 'When do refunds close?', $workspace->id);

        $this->assertStringContainsString('REFUND-MARKER', $capture['system']);
        $this->assertStringContainsString('SHIPPING-MARKER', $capture['system']);

        // The stronger hit is injected first, regardless of which base it came from.
        $this->assertLessThan(
            strpos($capture['system'], 'SHIPPING-MARKER'),
            strpos($capture['system'], 'REFUND-MARKER'),
        );
    }

    #[Test]
    public function an_identical_passage_in_two_bases_is_injected_once(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->configureOpenAi($workspace->id);

        $support = $this->seedKb($workspace->id, 'Support');
        $policies = $this->seedKb($workspace->id, 'Policies');

        $passage = 'SHARED-MARKER: our support desk answers within one business day.';
        $this->seedChunk($support, 'Support', $passage, [1.0, 0.0, 0.0]);
        $this->seedChunk($policies, 'Support', $passage, [1.0, 0.0, 0.0]);

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Dedupe bot',
            'system_prompt' => 'You are helpful.',
            'max_context_chunks' => 5,
            'enabled' => true,
        ]);
        $bot->knowledgeBases()->syncWithPivotValues([$support->id, $policies->id], ['workspace_id' => $workspace->id]);

        $capture = [];
        $this->fakeOpenAi($capture);

        app(ChatbotRunner::class)->runForApi($bot, 'How fast do you reply?', $workspace->id);

        $this->assertSame(1, substr_count($capture['system'], 'SHARED-MARKER'));
    }

    #[Test]
    public function a_base_with_a_different_vector_width_is_skipped_without_blocking_the_others(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $this->configureOpenAi($workspace->id);

        $matching = $this->seedKb($workspace->id, 'OpenAI base', 3);
        $other = $this->seedKb($workspace->id, 'Gemini base', 768);

        $this->seedChunk($matching, 'Refunds', 'REFUND-MARKER: refunds are open for 30 days.', [1.0, 0.0, 0.0]);
        $this->seedChunk($other, 'Other', 'OTHER-MARKER: not reachable with this provider.', [1.0, 0.0, 0.0]);

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Mixed bot',
            'system_prompt' => 'You are helpful.',
            'max_context_chunks' => 5,
            'enabled' => true,
        ]);
        $bot->knowledgeBases()->syncWithPivotValues([$matching->id, $other->id], ['workspace_id' => $workspace->id]);

        $capture = [];
        $this->fakeOpenAi($capture);

        app(ChatbotRunner::class)->runForApi($bot, 'Refund?', $workspace->id);

        $this->assertStringContainsString('REFUND-MARKER', $capture['system']);
        $this->assertStringNotContainsString('OTHER-MARKER', $capture['system']);
    }

    #[Test]
    public function the_update_endpoint_saves_multiple_bases_and_rejects_foreign_ones(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $kbA = $this->seedKb($workspace->id, 'A');
        $kbB = $this->seedKb($workspace->id, 'B');

        $foreign = $this->seedKb(
            $this->createWorkspaceContext()['workspace']->id,
            'Foreign',
        );

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Bot',
            'enabled' => true,
        ]);

        $this->actingAs($user)
            ->put(route('client.ai.chatbots.update', $bot->uuid), [
                'name' => 'Bot',
                'knowledge_base_ids' => [$kbA->id, $kbB->id],
                'enabled' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(
            [$kbA->id, $kbB->id],
            $bot->knowledgeBases()->orderBy('ai_knowledge_bases.id')->pluck('ai_knowledge_bases.id')->all(),
        );
        // The legacy single-base column follows the first selection.
        $this->assertSame($kbA->id, $bot->fresh()->ai_kb_id);

        $this->actingAs($user)
            ->put(route('client.ai.chatbots.update', $bot->uuid), [
                'name' => 'Bot',
                'knowledge_base_ids' => [$kbA->id, $foreign->id],
                'enabled' => true,
            ])
            ->assertStatus(422);

        $this->assertSame(
            [$kbA->id, $kbB->id],
            $bot->knowledgeBases()->orderBy('ai_knowledge_bases.id')->pluck('ai_knowledge_bases.id')->all(),
            'A rejected update must not drop the bases the bot already had.',
        );
    }

    #[Test]
    public function the_single_base_payload_still_works(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $kb = $this->seedKb($workspace->id, 'Legacy');

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Legacy bot',
            'enabled' => true,
        ]);

        $this->actingAs($user)
            ->put(route('client.ai.chatbots.update', $bot->uuid), [
                'name' => 'Legacy bot',
                'ai_kb_id' => $kb->id,
                'enabled' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([$kb->id], $bot->knowledgeBases()->pluck('ai_knowledge_bases.id')->all());
        $this->assertSame($kb->id, $bot->fresh()->ai_kb_id);

        // An explicit empty selection clears both the pivot and the legacy column.
        $this->actingAs($user)
            ->put(route('client.ai.chatbots.update', $bot->uuid), [
                'name' => 'Legacy bot',
                'knowledge_base_ids' => [],
                'enabled' => true,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame([], $bot->knowledgeBases()->pluck('ai_knowledge_bases.id')->all());
        $this->assertNull($bot->fresh()->ai_kb_id);
    }

    #[Test]
    public function a_cross_tenant_pivot_row_is_rejected_by_the_database(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $otherWorkspace = $this->createWorkspaceContext()['workspace'];

        $own = $this->seedKb($workspace->id, 'Own');
        $foreign = $this->seedKb($otherWorkspace->id, 'Foreign');

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Bot',
            'enabled' => true,
        ]);

        // A legitimate link is stored with the chatbot's workspace on the pivot.
        $bot->knowledgeBases()->syncWithPivotValues([$own->id], ['workspace_id' => $workspace->id]);

        $this->assertSame(
            [$own->id],
            $bot->fresh()->resolvedKnowledgeBases()->pluck('id')->all(),
        );

        $this->assertDatabaseHas('ai_chatbot_knowledge_base', [
            'chatbot_id' => $bot->id,
            'kb_id' => $own->id,
            'workspace_id' => $workspace->id,
        ]);

        // The composite foreign keys make a cross-tenant row impossible: the
        // pivot's workspace no longer matches the foreign base, so the insert
        // is refused instead of silently stored.
        $this->expectException(QueryException::class);

        $bot->knowledgeBases()->syncWithPivotValues([$foreign->id], ['workspace_id' => $workspace->id]);
    }

    #[Test]
    public function the_legacy_single_base_column_is_filtered_by_workspace(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $otherWorkspace = $this->createWorkspaceContext()['workspace'];

        $foreign = $this->seedKb($otherWorkspace->id, 'Foreign');

        $legacyBot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Legacy bot',
            'ai_kb_id' => $foreign->id,
            'enabled' => true,
        ]);

        $this->assertCount(0, $legacyBot->fresh()->resolvedKnowledgeBases());
    }
}
