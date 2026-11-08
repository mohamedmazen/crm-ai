<?php

namespace Tests\Feature\ProductionHardening;

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
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Content freshness and chunk quality: conditional re-indexing of URLs,
 * heading-aware chunking and the prompt context budget.
 */
class KbContentTest extends TestCase
{
    use RefreshDatabase;

    private function seedKb(int $workspaceId, int $dimensions = 3): AiKnowledgeBase
    {
        return AiKnowledgeBase::create([
            'workspace_id' => $workspaceId,
            'name' => 'Content KB',
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

    private function runIndexer(int $documentId, int $workspaceId): void
    {
        (new IndexDocumentJob($documentId, $workspaceId))->handle(
            app(LlmGateway::class),
            app(EmbeddingStore::class),
            app(StorageManager::class),
            app(SafeUrlFetcher::class),
        );
    }

    private function fakeEmbeddings(): void
    {
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
    }

    #[Test]
    public function a_docx_file_is_indexed_as_text(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);
        $this->configureOpenAi($workspace->id);
        $this->fakeEmbeddings();

        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('Warranty policy');
        $section->addText('The warranty lasts 24 months from purchase.');

        $tmp = tempnam(sys_get_temp_dir(), 'docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($tmp);

        Storage::fake('public');
        $storage = app(StorageManager::class);
        $path = $storage->prefixedPath('kb-docs/warranty.docx');
        $storage->disk()->put($path, file_get_contents($tmp));
        @unlink($tmp);

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Warranty.docx',
            'source_type' => 'file',
            'source_ref' => $path,
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id, $workspace->id);

        $this->assertTrue(
            $doc->chunks()->where('content', 'like', '%warranty lasts 24 months%')->exists(),
            'DOCX text should be extracted instead of indexing the zip binary.',
        );
    }

    #[Test]
    public function an_xlsx_file_is_indexed_with_its_sheet_name(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);
        $this->configureOpenAi($workspace->id);
        $this->fakeEmbeddings();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Pricing');
        $sheet->fromArray([
            ['Plan', 'Price'],
            ['Starter', 19],
            ['Pro', 49],
        ]);

        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        (new Xlsx($spreadsheet))->save($tmp);
        $spreadsheet->disconnectWorksheets();

        Storage::fake('public');
        $storage = app(StorageManager::class);
        $path = $storage->prefixedPath('kb-docs/pricing.xlsx');
        $storage->disk()->put($path, file_get_contents($tmp));
        @unlink($tmp);

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Pricing.xlsx',
            'source_type' => 'file',
            'source_ref' => $path,
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id, $workspace->id);

        $chunk = $doc->chunks()->orderBy('ord')->first();

        $this->assertNotNull($chunk);
        $this->assertSame('Pricing', $chunk->meta['heading']);
        $this->assertStringContainsString('Pro | 49', $chunk->content);
    }

    #[Test]
    public function an_unchanged_url_keeps_its_chunks_when_the_server_answers_304(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Policy page',
            'source_type' => 'url',
            'source_ref' => 'https://example.com/policy',
            'http_etag' => '"v1"',
            'status' => 'indexed',
        ]);

        $chunk = AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => 'Original policy text.',
            'tokens' => 4,
            'embedding' => json_encode([0.1, 0.2, 0.3]),
        ]);

        Http::fake([
            'https://example.com/policy' => Http::response('', 304, ['ETag' => '"v1"']),
        ]);

        $this->runIndexer($doc->id, $workspace->id);

        $this->assertDatabaseHas('ai_kb_chunks', ['id' => $chunk->id, 'content' => 'Original policy text.']);
        $this->assertSame('indexed', $doc->fresh()->status);
        $this->assertNotNull($doc->fresh()->last_indexed_at);

        Http::assertSent(fn ($request) => $request->hasHeader('If-None-Match', '"v1"'));
    }

    #[Test]
    public function chunking_keeps_section_headings_and_merges_paragraphs(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);
        $this->configureOpenAi($workspace->id);
        $this->fakeEmbeddings();

        Storage::fake('public');
        $storage = app(StorageManager::class);
        $path = $storage->prefixedPath('kb-docs/guide.md');
        $storage->disk()->put($path, "# Refunds\nOur refund policy is 30 days.\n\n# Shipping\nWe ship worldwide.");

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Guide',
            'source_type' => 'file',
            'source_ref' => $path,
            'status' => 'pending',
        ]);

        $this->runIndexer($doc->id, $workspace->id);

        $chunks = $doc->chunks()->orderBy('ord')->get();

        $this->assertCount(2, $chunks);
        $this->assertSame('Refunds', $chunks[0]->meta['heading']);
        $this->assertStringContainsString('Our refund policy is 30 days.', $chunks[0]->content);
        $this->assertSame('Shipping', $chunks[1]->meta['heading']);
        $this->assertStringContainsString('We ship worldwide.', $chunks[1]->content);
    }

    #[Test]
    public function the_prompt_context_is_capped_to_the_configured_budget(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);
        $this->configureOpenAi($workspace->id);

        $doc = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Huge page',
            'source_type' => 'text',
            'source_ref' => 'Huge page',
            'status' => 'indexed',
        ]);
        AiKbChunk::create([
            'kb_id' => $kb->id,
            'document_id' => $doc->id,
            'ord' => 0,
            'content' => str_repeat('context ', 1000).'TAIL-MARKER',
            'tokens' => 2000,
            'embedding' => json_encode([1.0, 0.0, 0.0]),
        ]);

        $bot = AiChatbot::create([
            'workspace_id' => $workspace->id,
            'name' => 'Bot',
            'ai_kb_id' => $kb->id,
            'system_prompt' => 'You are helpful.',
            'max_context_chunks' => 1,
            'enabled' => true,
        ]);

        config(['ai.retrieval.max_context_tokens' => 50]);

        $captured = null;

        Http::fake([
            'api.openai.com/v1/embeddings' => Http::response([
                'data' => [['embedding' => [1.0, 0.0, 0.0]]],
            ], 200),
            'api.openai.com/v1/chat/completions' => function ($request) use (&$captured) {
                $body = json_decode($request->body(), true);
                $captured = collect($body['messages'])->firstWhere('role', 'system')['content'] ?? '';

                return Http::response([
                    'choices' => [['message' => ['content' => 'ok']]],
                    'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
                    'model' => 'gpt-4o-mini',
                ], 200);
            },
        ]);

        app(ChatbotRunner::class)->runForApi($bot, 'Tell me about the page.', $workspace->id);

        $this->assertNotNull($captured);
        $this->assertStringNotContainsString('TAIL-MARKER', $captured);

        // 50 tokens ≈ 200 characters: the chunk is cut exactly at the budget.
        $expected = rtrim(substr(str_repeat('context ', 1000), 0, 200));
        $this->assertStringContainsString($expected."\nCONTEXT", $captured);
    }

    #[Test]
    public function the_refresh_command_only_queues_stale_url_documents(): void
    {
        Queue::fake();

        ['workspace' => $workspace] = $this->createWorkspaceContext();
        $kb = $this->seedKb($workspace->id);

        $stale = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Old page',
            'source_type' => 'url',
            'source_ref' => 'https://example.com/old',
            'status' => 'indexed',
            'last_indexed_at' => now()->subDays(3),
        ]);

        $fresh = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'New page',
            'source_type' => 'url',
            'source_ref' => 'https://example.com/new',
            'status' => 'indexed',
            'last_indexed_at' => now(),
        ]);

        $text = AiKbDocument::create([
            'kb_id' => $kb->id,
            'title' => 'Static text',
            'source_type' => 'text',
            'source_ref' => 'Static text',
            'status' => 'indexed',
            'last_indexed_at' => now()->subDays(3),
        ]);

        $this->artisan('ai:refresh-knowledge-bases', ['--hours' => 24])->assertSuccessful();

        Queue::assertPushed(IndexDocumentJob::class, 1);
        Queue::assertPushed(
            IndexDocumentJob::class,
            fn (IndexDocumentJob $job) => $job->documentId === $stale->id,
        );

        $this->assertSame('pending', $stale->fresh()->status);
        $this->assertSame('indexed', $fresh->fresh()->status);
        $this->assertSame('indexed', $text->fresh()->status);
    }
}
