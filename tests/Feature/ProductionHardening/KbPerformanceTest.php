<?php

namespace Tests\Feature\ProductionHardening;

use App\Models\User;
use App\Models\Workspace;
use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Models\AiProviderConfig;
use App\Modules\AI\Services\ChatbotRunner;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\LlmGateway;
use App\Services\StorageManager;
use App\Support\Http\SafeUrlFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Performance and idempotency guards for knowledge-base retrieval/indexing:
 * batched MySQL search, cached query embeddings, retry reuse and document
 * de-duplication.
 */
class KbPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function createUserWithWorkspace(): array
    {
        $user = User::factory()->create(['role' => 'client', 'email_verified_at' => now()]);
        $workspace = Workspace::factory()->create(['owner_id' => $user->id]);
        $user->update(['workspace_id' => $workspace->id]);
        $user->refresh();

        return [$user, $workspace];
    }

    private function seedKb(int $workspaceId, int $dimensions = 3): AiKnowledgeBase
    {
        return AiKnowledgeBase::create([
            'workspace_id' => $workspaceId,
            'name' => 'Perf KB',
            'embedding_model' => 'text-embedding-3-small',
            'dimensions' => $dimensions,
            'status' => 'active',
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

    #[Test]
    public function mysql_search_scans_every_batch_and_returns_the_best_matches(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);
        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Large doc',
            'source_type' => 'text',
            'source_ref' => 'x',
            'status' => 'indexed',
        ]);

        // More chunks than one chunkById() batch, so a batching bug would miss
        // the best match at the end of the table.
        $rows = [];
        for ($i = 0; $i < 550; $i++) {
            $rows[] = [
                'kb_id' => $kb->id,
                'document_id' => $doc->id,
                'ord' => $i,
                'content' => 'chunk '.$i,
                'tokens' => 2,
                'embedding' => json_encode([0.0, 1.0, 0.0]),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        AiKbChunk::insert($rows);

        $best = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 999,
            'content' => 'the answer chunk',
            'tokens' => 3,
            'embedding' => json_encode([1.0, 0.0, 0.0]),
        ]);

        config(['ai.retrieval.min_score' => 0.0, 'services.qdrant.url' => '']);

        $results = app(EmbeddingStore::class)->search($kb->id, [1.0, 0.0, 0.0], 3);

        $this->assertCount(3, $results);
        $this->assertSame($best->id, $results[0]['chunk']->id);
        $this->assertEqualsWithDelta(1.0, $results[0]['score'], 0.0001);
    }

    #[Test]
    public function repeated_queries_reuse_the_cached_embedding(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Policy',
            'source_type' => 'text',
            'source_ref' => 'Our refund policy is 30 days.',
            'status' => 'indexed',
        ]);
        AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => 'Our refund policy is 30 days.',
            'tokens' => 8,
            'embedding' => json_encode([1.0, 0.0, 0.0]),
        ]);

        $this->configureOpenAi($workspace->id);

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Bot',
            'ai_kb_id' => $kb->id,
            'system_prompt' => 'You are helpful.',
            'max_context_chunks' => 3,
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

        $runner = app(ChatbotRunner::class);
        $runner->runForApi($bot, 'What is the refund policy?', $workspace->id);
        $runner->runForApi($bot, 'What is the refund policy?', $workspace->id);

        $embeddingCalls = collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/embeddings'))
            ->count();

        $this->assertSame(1, $embeddingCalls, 'The second identical query must come from cache.');
    }

    #[Test]
    public function a_retry_reuses_embeddings_from_the_previous_attempt(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);
        $this->configureOpenAi($workspace->id);

        Storage::fake('public');
        $storage = app(StorageManager::class);
        $path = $storage->prefixedPath('kb-docs/long.txt');
        $storage->disk()->put($path, trim(str_repeat('word ', 900)));

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Long text',
            'source_type' => 'file',
            'source_ref' => $path,
            'status' => 'pending',
        ]);

        Http::fake([
            'api.openai.com/v1/embeddings' => function ($request) {
                $inputs = json_decode($request->body(), true)['input'] ?? [];

                return Http::response([
                    'data' => array_map(
                        fn () => ['embedding' => [0.1, 0.2, 0.3]],
                        array_values($inputs),
                    ),
                ], 200);
            },
        ]);

        $handle = function (IndexDocumentJob $job) {
            $job->handle(
                app(LlmGateway::class),
                app(EmbeddingStore::class),
                app(StorageManager::class),
                app(SafeUrlFetcher::class),
            );
        };

        $handle(new IndexDocumentJob($doc->id, $workspace->id));

        $chunkCount = $doc->chunks()->count();
        $this->assertGreaterThan(1, $chunkCount, 'The fixture must produce more than one chunk.');

        // Simulate the queue retrying the job (attempt 2).
        $retry = new class($doc->id, $workspace->id) extends IndexDocumentJob
        {
            public function attempts(): int
            {
                return 2;
            }
        };

        $handle($retry);

        $embeddingCalls = collect(Http::recorded())
            ->filter(fn (array $pair) => str_contains($pair[0]->url(), '/embeddings'))
            ->count();

        $this->assertSame(1, $embeddingCalls, 'A retry must not re-embed chunks that already succeeded.');
        $this->assertSame($chunkCount, $doc->chunks()->count());
        $this->assertSame('indexed', $doc->fresh()->status);
    }

    #[Test]
    public function adding_the_same_source_twice_is_rejected(): void
    {
        Queue::fake();

        [$user] = $this->createUserWithWorkspace();

        $this->actingAs($user)->post('/app/ai/knowledge-bases', ['name' => 'Dedupe KB']);
        $kb = AiKnowledgeBase::where('name', 'Dedupe KB')->firstOrFail();

        $payload = [
            'source_type' => 'text',
            'source_ref' => 'Same content every time.',
            'title' => 'Same content',
        ];

        $this->actingAs($user)
            ->post("/app/ai/knowledge-bases/{$kb->uuid}/documents", $payload)
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->post("/app/ai/knowledge-bases/{$kb->uuid}/documents", $payload)
            ->assertSessionHasErrors('source_ref');

        $this->assertSame(1, AiKbDocument::where('kb_id', $kb->id)->count());
    }
}
