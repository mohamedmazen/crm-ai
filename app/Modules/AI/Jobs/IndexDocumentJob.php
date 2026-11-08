<?php

namespace App\Modules\AI\Jobs;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\AI\Models\AiKbDocument;
use App\Modules\AI\Models\AiKnowledgeBase;
use App\Modules\AI\Services\EmbeddingStore;
use App\Modules\AI\Services\Llm\LlmManager;
use App\Modules\AI\Services\LlmGateway;
use App\Services\StorageManager;
use App\Support\Http\SafeUrlFetcher;
use App\Support\Queue\Middleware\LimitTenantConcurrency;
use App\Support\Queue\TenantAwareJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use League\HTMLToMarkdown\HtmlConverter;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser;

class IndexDocumentJob implements ShouldQueue, TenantAwareJob
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Large PDFs and sitemap expansions routinely exceed two minutes; the
     * queue connection's retry_after (1200s) leaves headroom for this.
     */
    public int $timeout = 300;

    /**
     * Owning workspace; null only for jobs queued before this field existed.
     *
     * Declared outside the constructor on purpose: a promoted property's default
     * is a constructor-argument default, and unserialize() does not invoke the
     * constructor, so a legacy payload would leave it uninitialized.
     */
    public ?int $workspaceId = null;

    public function __construct(
        public readonly int $documentId,
        ?int $workspaceId = null,
    ) {
        $this->workspaceId = $workspaceId;
    }

    public function tenantWorkspaceId(): ?int
    {
        return $this->workspaceId ?? AiKbDocument::find($this->documentId)?->knowledgeBase?->workspace_id;
    }

    /**
     * Indexing is CPU- and API-heavy: one document per workspace at a time so a
     * bulk upload cannot occupy every worker.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new LimitTenantConcurrency(
                maxConcurrent: (int) config('queue.tenant_limits.concurrent_ai_jobs', 1),
                bucket: 'ai',
                lockSeconds: 180,
            ),
        ];
    }

    public function handle(LlmGateway $llm, EmbeddingStore $store, StorageManager $storage, SafeUrlFetcher $fetcher): void
    {
        $doc = AiKbDocument::with('chunks')->find($this->documentId);
        if (! $doc) {
            return;
        }

        $kb = $doc->knowledgeBase;

        $doc->update(['status' => 'indexing']);
        $kb?->update(['status' => 'indexing']);

        try {
            $text = $this->extractText($doc, $storage, $fetcher);

            // null means the remote source answered 304 Not Modified: its
            // chunks and vectors are still valid, so only the freshness stamp
            // changes.
            if ($text === null) {
                $doc->update(['status' => 'indexed', 'last_indexed_at' => now()]);
                $this->refreshKnowledgeBaseStatus($kb);

                return;
            }

            $chunks = $this->chunk($text);

            // On a retry, keep the embeddings an earlier attempt already paid
            // for: only the chunks that never completed are sent to the
            // provider again. A fresh dispatch (attempt 1) re-embeds everything,
            // so switching embedding providers still rebuilds the index.
            $reusable = $this->attempts() > 1 ? $this->reusableEmbeddings($doc) : [];

            // Drop any vectors stored for this document (Qdrant) before the
            // chunks they point at are recreated.
            $store->deleteForDocument($doc->id);

            // Remove old chunks
            $doc->chunks()->delete();

            $kbId = $kb?->id ?? 0;

            $chunkModels = [];
            foreach ($chunks as $i => $chunkData) {
                $chunkModels[] = AiKbChunk::create([
                    'kb_id' => $kbId,
                    'document_id' => $doc->id,
                    'ord' => $i,
                    'content' => $chunkData['content'],
                    'tokens' => (int) (strlen($chunkData['content']) / 4),
                    'meta' => $chunkData['heading'] !== null ? ['heading' => $chunkData['heading']] : null,
                ]);
            }

            // Embed all chunks.
            //
            // A missing embedding provider (Anthropic-only or none configured) is a
            // non-fatal condition: the document is still indexed as plain text and we
            // log it so operators can see RAG won't work until a provider is added.
            //
            // A transient embedding API error, by contrast, is allowed to propagate so
            // the queue retries — rather than silently marking the document "indexed"
            // with no vectors.
            $workspaceId = $kb?->workspace_id ?? 0;
            $dimensions = null;

            if ($workspaceId && ! empty($chunkModels)) {
                if ($this->embedProviderAvailable($workspaceId)) {
                    // Re-apply embeddings reused from the failed attempt; they
                    // are re-upserted to Qdrant because the document's points
                    // were just deleted.
                    $pending = [];
                    foreach ($chunkModels as $chunk) {
                        $reused = $reusable[sha1($chunk->content)] ?? null;
                        $decoded = $reused === null ? [] : $this->decodeEmbedding($reused);

                        if (empty($decoded)) {
                            $pending[] = $chunk;

                            continue;
                        }

                        $this->trackDimensions($doc, $store, $dimensions, count($decoded));
                        $store->storeEmbedding($chunk, $decoded);
                    }

                    foreach (array_chunk($pending, 20) as $batch) {
                        $texts = array_map(fn ($c) => $c->content, $batch);
                        $embeddings = $llm->embed($workspaceId, $texts);

                        foreach ($batch as $j => $chunk) {
                            $embedding = $embeddings[$j] ?? null;
                            if (empty($embedding)) {
                                continue;
                            }

                            $this->trackDimensions($doc, $store, $dimensions, count($embedding));
                            $store->storeEmbedding($chunk, $embedding);
                        }
                    }

                    if ($dimensions !== null && $kb) {
                        $this->assertDimensionConsistency($doc, $kb, $dimensions, $store);
                    }
                } else {
                    Log::warning('IndexDocumentJob: indexed without embeddings — no embedding-capable provider configured', [
                        'document_id' => $doc->id,
                        'kb_id' => $kbId,
                        'workspace_id' => $workspaceId,
                    ]);
                }
            }

            $doc->update([
                'status' => 'indexed',
                'last_indexed_at' => now(),
                'tokens' => array_sum(array_map(fn ($c) => $c->tokens, $chunkModels)),
            ]);

            $this->refreshKnowledgeBaseStatus($kb);
        } catch (\Throwable $e) {
            $doc->update(['status' => 'error']);
            $this->refreshKnowledgeBaseStatus($kb);

            throw $e;
        }
    }

    /**
     * Keep the base's status honest: `indexing` while documents are queued or
     * running, `error` when at least one document failed, `active` otherwise.
     */
    private function refreshKnowledgeBaseStatus(?AiKnowledgeBase $kb): void
    {
        if (! $kb) {
            return;
        }

        $statuses = AiKbDocument::where('kb_id', $kb->id)->pluck('status');

        $status = match (true) {
            $statuses->contains(fn ($s) => in_array($s, ['pending', 'indexing'], true)) => 'indexing',
            $statuses->contains('error') => 'error',
            default => 'active',
        };

        if ($kb->status !== $status) {
            $kb->update(['status' => $status]);
        }
    }

    /** True when the workspace has an embedding-capable provider (OpenAI/Gemini). */
    private function embedProviderAvailable(int $workspaceId): bool
    {
        // Any failure to RESOLVE a provider (none configured, orphaned workspace,
        // malformed config) is treated as "no embeddings" — a non-fatal condition,
        // so the document still indexes as plain text. This deliberately does NOT
        // swallow errors from the actual embed() call below, which must still
        // propagate so the queue retries rather than indexing with no vectors.
        try {
            LlmManager::forWorkspaceEmbed($workspaceId);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Embeddings from a previous (failed) attempt, keyed by chunk-text hash.
     *
     * @return array<string, string> sha1(text) => raw embedding JSON
     */
    private function reusableEmbeddings(AiKbDocument $doc): array
    {
        return $doc->chunks
            ->filter(fn (AiKbChunk $chunk) => filled($chunk->embedding))
            ->mapWithKeys(fn (AiKbChunk $chunk) => [sha1($chunk->content) => (string) $chunk->embedding])
            ->all();
    }

    /**
     * @return list<float>
     */
    private function decodeEmbedding(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Refuse to mix vector widths inside one document. A provider that returns
     * a different width mid-document would leave some chunks permanently
     * unreachable, so the half-built index is removed before failing.
     *
     * @param  int|null  $dimensions  First width seen (passed by reference)
     *
     * @throws \RuntimeException
     */
    private function trackDimensions(AiKbDocument $doc, EmbeddingStore $store, ?int &$dimensions, int $width): void
    {
        if ($dimensions === null) {
            $dimensions = $width;

            return;
        }

        if ($dimensions === $width) {
            return;
        }

        $store->deleteForDocument($doc->id);
        $doc->chunks()->delete();

        throw new \RuntimeException(
            "Embedding dimension mismatch: chunks in this document produced {$dimensions}-dimensional and "
            ."{$width}-dimensional vectors. Re-index the whole knowledge base after switching embedding providers or models."
        );
    }

    /**
     * Refuse to mix vectors of different widths inside one knowledge base.
     *
     * OpenAI returns 1536 dimensions and Gemini 768. Cosine similarity between
     * vectors of different lengths is meaningless — the cosine helper scores it
     * 0.0 — so switching providers without re-indexing silently turns retrieval
     * into "pick the first k chunks". Fail loudly instead.
     *
     * @throws \RuntimeException
     */
    private function assertDimensionConsistency(AiKbDocument $doc, AiKnowledgeBase $kb, int $dimensions, EmbeddingStore $store): void
    {
        $sibling = AiKbChunk::where('kb_id', $kb->id)
            ->where('document_id', '!=', $doc->id)
            ->whereNotNull('embedding')
            ->value('embedding');

        if ($sibling !== null) {
            $siblingDimensions = count(json_decode((string) $sibling, true) ?: []);

            if ($siblingDimensions > 0 && $siblingDimensions !== $dimensions) {
                // Do not leave a half-indexed document behind — its vectors
                // would never match the rest of the knowledge base.
                $store->deleteForDocument($doc->id);
                $doc->chunks()->delete();

                throw new \RuntimeException(
                    "Embedding dimension mismatch: the knowledge base already stores {$siblingDimensions}-dimensional "
                    ."vectors but the configured provider returned {$dimensions}. Re-index the whole knowledge base "
                    .'after switching embedding providers or models.'
                );
            }
        }

        if ((int) $kb->dimensions !== $dimensions) {
            $kb->update(['dimensions' => $dimensions]);
        }
    }

    /**
     * Extract indexable text.
     *
     * Returns null when a remote source answers 304 Not Modified, which tells
     * the caller to keep the existing chunks instead of rebuilding them.
     */
    private function extractText(AiKbDocument $doc, StorageManager $storage, SafeUrlFetcher $fetcher): ?string
    {
        return match ($doc->source_type) {
            'text' => $doc->source_ref ?? '',
            'url' => $this->fetchUrl($doc, $doc->source_ref ?? '', $fetcher),
            'file' => $this->readFile($doc->source_ref ?? '', $storage),
            'faq' => $this->formatFaq($doc->source_ref ?? ''),
            'sitemap' => $this->processSitemap($doc, $fetcher),
            default => '',
        };
    }

    /**
     * Fetch a page with conditional headers when validators are stored.
     *
     * @return string|null null when the page is unchanged (304)
     */
    private function fetchUrl(AiKbDocument $doc, string $url, SafeUrlFetcher $fetcher): ?string
    {
        if (empty($url)) {
            return '';
        }

        $response = $fetcher->fetch($url, 30, $this->conditionalHeaders($doc));

        if ($response['not_modified']) {
            return null;
        }

        $this->storeValidators($doc, $response);

        $body = $response['body'];
        if ($body === null) {
            return '';
        }

        // Convert HTML to Markdown using league/html-to-markdown, then strip remaining tags
        if (class_exists(HtmlConverter::class)) {
            $converter = new HtmlConverter(['strip_tags' => true]);

            return $converter->convert($body);
        }

        return strip_tags($body);
    }

    /**
     * @return array<string, string>
     */
    private function conditionalHeaders(AiKbDocument $doc): array
    {
        $headers = [];

        if (! empty($doc->http_etag)) {
            $headers['If-None-Match'] = $doc->http_etag;
        }

        if (! empty($doc->http_last_modified)) {
            $headers['If-Modified-Since'] = $doc->http_last_modified;
        }

        return $headers;
    }

    /**
     * Persist the validators returned by a 200 so the next refresh can be
     * conditional. Clearing them when the server stops sending them avoids
     * comparing against stale values forever.
     *
     * @param  array{etag: ?string, last_modified: ?string}  $response
     */
    private function storeValidators(AiKbDocument $doc, array $response): void
    {
        $etag = $response['etag'] ?? null;
        $lastModified = $response['last_modified'] ?? null;

        if ($doc->http_etag === $etag && $doc->http_last_modified === $lastModified) {
            return;
        }

        $doc->update([
            'http_etag' => $etag,
            'http_last_modified' => $lastModified,
        ]);
    }

    /**
     * Read an uploaded document back from the active storage disk.
     *
     * The stored source_ref is a disk-relative key (e.g. "uploads/kb-docs/x.pdf"),
     * NOT an absolute local path, so it must be read through the Storage disk —
     * which works for both the local "public" disk and cloud disks (S3/Spaces/Wasabi).
     */
    private function readFile(string $path, StorageManager $storage): string
    {
        if ($path === '') {
            return '';
        }

        $disk = $storage->disk();
        if (! $disk->exists($path)) {
            return '';
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === 'pdf') {
            $tmp = tempnam(sys_get_temp_dir(), 'kbpdf_');

            try {
                file_put_contents($tmp, $disk->get($path));

                return (new Parser)->parseFile($tmp)->getText();
            } finally {
                @unlink($tmp);
            }
        }

        if (in_array($ext, ['docx', 'doc', 'xlsx', 'xls'], true)) {
            $tmp = tempnam(sys_get_temp_dir(), 'kb_');

            try {
                file_put_contents($tmp, $disk->get($path));

                return $ext === 'docx' || $ext === 'doc'
                    ? $this->extractWordText($tmp)
                    : $this->extractSpreadsheetText($tmp);
            } catch (\Throwable $e) {
                // Never index raw binary as text: a corrupt upload would poison
                // the knowledge base with unreadable embeddings.
                Log::warning('IndexDocumentJob: document text extraction failed', [
                    'path' => $path,
                    'extension' => $ext,
                    'error' => $e->getMessage(),
                ]);

                return '';
            } finally {
                @unlink($tmp);
            }
        }

        return (string) ($disk->get($path) ?? '');
    }

    /**
     * Extract readable text from a .docx/.doc file, including tables.
     */
    private function extractWordText(string $path): string
    {
        $document = IOFactory::load($path);

        $parts = [];
        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $text = $this->wordElementText($element);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }

        return trim(implode("\n\n", $parts));
    }

    private function wordElementText(mixed $element): string
    {
        if ($element instanceof Table) {
            $rows = [];
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cellParts = [];
                    foreach ($cell->getElements() as $child) {
                        $text = $this->wordElementText($child);
                        if ($text !== '') {
                            $cellParts[] = $text;
                        }
                    }
                    $cells[] = implode(' ', $cellParts);
                }
                $rows[] = implode(' | ', $cells);
            }

            return trim(implode("\n", $rows));
        }

        if ($element instanceof TextRun) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $text = $this->wordElementText($child);
                if ($text !== '') {
                    $parts[] = $text;
                }
            }

            return trim(implode(' ', $parts));
        }

        if (is_object($element) && method_exists($element, 'getText')) {
            $text = $element->getText();

            if (is_array($text)) {
                return trim(implode(' ', array_filter($text, 'is_scalar')));
            }

            return is_string($text) ? trim($text) : '';
        }

        return '';
    }

    /**
     * Flatten a spreadsheet into text: one line per row, cells separated by
     * " | ", sheet titles as Markdown headings. Capped so a huge export cannot
     * exhaust worker memory.
     */
    private function extractSpreadsheetText(string $path): string
    {
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($path);

        $lines = [];
        $rowLimit = 5000;

        try {
            foreach ($spreadsheet->getWorksheetIterator() as $sheet) {
                $lines[] = '## '.$sheet->getTitle();
                $rowCount = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    if (++$rowCount > $rowLimit) {
                        $lines[] = '… (truncated)';
                        break;
                    }

                    $cells = [];
                    $cellIterator = $row->getCellIterator();
                    $cellIterator->setIterateOnlyExistingCells(false);

                    foreach ($cellIterator as $cell) {
                        $value = $cell->getValue();

                        if ($value === null || $value === '') {
                            continue;
                        }

                        if (is_bool($value)) {
                            $value = $value ? 'TRUE' : 'FALSE';
                        }

                        $cells[] = trim((string) $value);
                    }

                    $line = trim(implode(' | ', $cells));
                    if ($line !== '') {
                        $lines[] = $line;
                    }
                }
            }
        } finally {
            $spreadsheet->disconnectWorksheets();
        }

        return trim(implode("\n", $lines));
    }

    /**
     * Turn the FAQ payload (a JSON array of {question, answer} pairs produced by the
     * UI) into clean, embeddable text. Falls back to the raw string if it isn't JSON.
     */
    private function formatFaq(string $raw): string
    {
        if (trim($raw) === '') {
            return '';
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            // Not JSON — treat as plain text.
            return $raw;
        }

        $parts = [];
        foreach ($decoded as $pair) {
            if (! is_array($pair)) {
                continue;
            }
            $q = trim((string) ($pair['question'] ?? ''));
            $a = trim((string) ($pair['answer'] ?? ''));
            if ($q === '' && $a === '') {
                continue;
            }
            $parts[] = "Q: {$q}\nA: {$a}";
        }

        return implode("\n\n", $parts);
    }

    /**
     * Parse a sitemap and fan out one lightweight child job per page URL.
     *
     * This job stays cheap on purpose: it only fetches + parses the XML and
     * enqueues child "url" documents. The actual page crawling/embedding happens
     * in those child jobs on the queue, so the originating web request never
     * blocks on hundreds of HTTP fetches (which previously caused a 502 when the
     * queue ran synchronously).
     *
     * Handles both <urlset> (a flat list of pages) and <sitemapindex> (a list of
     * nested sitemaps, e.g. Yoast/WordPress) — for the latter, each nested sitemap
     * is enqueued as its own "sitemap" child and expanded recursively.
     */
    private function processSitemap(AiKbDocument $doc, SafeUrlFetcher $fetcher): ?string
    {
        $sitemapUrl = $doc->source_ref ?? '';
        if (empty($sitemapUrl)) {
            return '';
        }

        $response = $fetcher->fetch($sitemapUrl, 20, $this->conditionalHeaders($doc));

        if ($response['not_modified']) {
            return null;
        }

        $this->storeValidators($doc, $response);

        $body = $response['body'];
        if ($body === null) {
            return '';
        }

        try {
            // LIBXML_NONET blocks external entity/DTD resolution (XXE).
            $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET);
            if ($xml === false) {
                throw new \RuntimeException('Unparseable sitemap XML');
            }

            // <sitemapindex> → nested sitemaps; <urlset> → page URLs.
            $isIndex = isset($xml->sitemap);
            $childType = $isIndex ? 'sitemap' : 'url';

            $locs = [];
            foreach (($isIndex ? $xml->sitemap : $xml->url) as $node) {
                $loc = trim((string) $node->loc);
                if ($loc !== '') {
                    $locs[$loc] = true; // dedupe by URL
                }
            }

            // Re-expanding a sitemap used to create a second copy of every
            // child document (and therefore duplicate chunks). Skip URLs that
            // are already indexed in this knowledge base.
            $existing = AiKbDocument::where('kb_id', $doc->kb_id)
                ->whereIn('source_ref', array_keys($locs))
                ->pluck('source_ref')
                ->all();

            $newLocs = array_values(array_diff(array_keys($locs), $existing));

            foreach (array_slice($newLocs, 0, 200) as $loc) {
                $child = AiKbDocument::create([
                    'kb_id' => $doc->kb_id,
                    'title' => $loc,
                    'source_type' => $childType,
                    'source_ref' => $loc,
                    'status' => 'pending',
                ]);
                static::dispatch($child->id)->onQueue('ai');
            }
        } catch (\Throwable) {
            // Malformed sitemap; fall back to fetching the URL as HTML
            return $this->fetchUrl($doc, $sitemapUrl, $fetcher);
        }

        return '';
    }

    /**
     * Split text into embedding-sized chunks while keeping section headings.
     *
     * Paragraphs under the same heading are merged until they reach `$size`
     * words, so a page made of short paragraphs no longer produces dozens of
     * tiny low-signal chunks. Long paragraphs are windows with `$overlap`
     * words of carry-over, and a heading change starts a new chunk so a chunk
     * is never labelled with the wrong section.
     *
     * @return array<int, array{content: string, heading: ?string}>
     */
    private function chunk(string $text, int $size = 800, int $overlap = 100): array
    {
        $text = trim(preg_replace('/\r\n?/', "\n", $text) ?? '');
        if ($text === '') {
            return [];
        }

        $size = max(1, $size);
        $overlap = max(0, min($overlap, $size - 1));

        $chunks = [];
        $words = [];
        $heading = null;
        $newWords = 0;

        $emit = function () use (&$chunks, &$words, &$heading, &$newWords): void {
            if ($words !== [] && $newWords > 0) {
                $chunks[] = [
                    'content' => $heading !== null
                        ? $heading."\n\n".implode(' ', $words)
                        : implode(' ', $words),
                    'heading' => $heading,
                ];
            }

            $newWords = 0;
        };

        foreach ($this->splitBlocks($text) as $block) {
            $blockWords = preg_split('/\s+/', trim($block['text'])) ?: [];
            if ($blockWords === []) {
                continue;
            }

            if ($heading !== $block['heading'] && $words !== []) {
                $emit();
                $words = [];
            }

            $heading = $block['heading'];

            foreach ($blockWords as $word) {
                $words[] = $word;
                $newWords++;

                if (count($words) >= $size) {
                    $emit();
                    $words = $overlap > 0 ? array_slice($words, -$overlap) : [];
                }
            }
        }

        $emit();

        return $chunks;
    }

    /**
     * Break text into paragraphs, tracking Markdown headings.
     *
     * @return array<int, array{heading: ?string, text: string}>
     */
    private function splitBlocks(string $text): array
    {
        $blocks = [];
        $buffer = [];
        $heading = null;

        $flush = function () use (&$blocks, &$buffer, &$heading): void {
            $paragraph = trim(implode("\n", $buffer));
            if ($paragraph !== '') {
                $blocks[] = ['heading' => $heading, 'text' => $paragraph];
            }
            $buffer = [];
        };

        foreach (preg_split('/\n/', $text) ?: [] as $line) {
            $trimmed = trim($line);

            if (preg_match('/^#{1,6}\s+(.+)$/', $trimmed, $matches)) {
                $flush();
                $heading = trim($matches[1]);

                continue;
            }

            if ($trimmed === '') {
                $flush();

                continue;
            }

            $buffer[] = $trimmed;
        }

        $flush();

        if ($blocks === []) {
            $blocks[] = ['heading' => null, 'text' => trim($text)];
        }

        return $blocks;
    }
}
