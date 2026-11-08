<?php

namespace App\Modules\AI\Services;

use App\Modules\AI\Models\AiKbChunk;
use App\Modules\Integrations\Services\CredentialResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Vector storage and similarity search.
 *
 * Uses Qdrant when QDRANT_URL is set in env; otherwise falls back to
 * MySQL JSON storage with in-PHP cosine similarity (suitable for ~50k chunks).
 */
class EmbeddingStore
{
    private const QDRANT_COLLECTION = 'kb_chunks';

    /**
     * Words too common to signal relevance, dropped before the keyword boost.
     * Mixed English/Arabic because a workspace can serve both.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        // English
        'the', 'and', 'for', 'with', 'you', 'your', 'yours', 'are', 'was', 'were',
        'what', 'when', 'where', 'which', 'who', 'whom', 'how', 'why', 'can',
        'could', 'should', 'would', 'does', 'did', 'from', 'this', 'that',
        'these', 'those', 'have', 'has', 'had', 'not', 'but', 'all', 'any',
        'our', 'their', 'about', 'into', 'over', 'under', 'please', 'there',
        'here', 'they', 'them', 'its', 'get', 'got',
        // Arabic
        'من', 'في', 'على', 'عن', 'إلى', 'الى', 'هو', 'هي', 'هل', 'ما', 'ماذا',
        'لماذا', 'كيف', 'متى', 'أين', 'اين', 'هذا', 'هذه', 'ذلك', 'التي', 'الذي',
        'مع', 'أو', 'او', 'ثم', 'قد', 'كان', 'كانت', 'يكون', 'لكن', 'حتى', 'بعد',
        'قبل', 'كل', 'بعض', 'عند', 'عندما', 'إن', 'ان', 'أن',
    ];

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    public function storeEmbedding(AiKbChunk $chunk, array $embedding): void
    {
        // Always persist to MySQL so chunks remain queryable without Qdrant
        $chunk->update(['embedding' => json_encode($embedding)]);

        if ($this->qdrantEnabled()) {
            $this->qdrantUpsert($chunk, $embedding);
        }
    }

    /** Find top-k most similar chunks to the query embedding. */
    /**
     * Find top-k most similar chunks to the query embedding.
     *
     * When the raw `$query` text is supplied, exact keyword hits get a small
     * boost on top of cosine similarity (hybrid search): product codes, names
     * and rare terms that the embedding model glosses over still surface.
     */
    public function search(int $kbId, array $queryEmbedding, int $topK = 5, string $query = ''): array
    {
        $topK = max(1, $topK);
        $terms = $this->queryTerms($query);
        $boost = $this->keywordBoost($terms);

        if ($this->qdrantEnabled()) {
            // Over-fetch so a keyword-heavy chunk outside the vector top-k can
            // still be re-ranked into the result.
            $fetchK = $terms === [] ? $topK : min($topK * 3, 100);

            $results = $this->qdrantSearch($kbId, $queryEmbedding, $fetchK);
            if (! empty($results)) {
                $results = $this->applyKeywordBoost($results, $terms, $boost);

                return array_slice($this->filterByScore($results), 0, $topK);
            }
            // Fall through to MySQL if Qdrant returns nothing (e.g. collection empty)
        }

        return $this->filterByScore($this->mysqlSearch($kbId, $queryEmbedding, $topK, $terms, $boost));
    }

    /**
     * Remove every vector belonging to a document (used before re-indexing and
     * when a document is deleted). Without this Qdrant keeps stale points that
     * can still be retrieved by similarity search.
     */
    public function deleteForDocument(int $documentId): void
    {
        if (! $this->qdrantEnabled()) {
            return;
        }

        try {
            $this->qdrantClient()->post('/collections/'.self::QDRANT_COLLECTION.'/points/delete', [
                'filter' => [
                    'must' => [['key' => 'document_id', 'match' => ['value' => $documentId]]],
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Qdrant delete failed; stale points may remain', [
                'document_id' => $documentId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Drop hits below the configured similarity floor so a question the
     * knowledge base cannot answer does not inject unrelated chunks into the
     * prompt (which invites the model to hallucinate).
     *
     * @param  array<int, array{chunk: AiKbChunk, score: float}>  $results
     * @return array<int, array{chunk: AiKbChunk, score: float}>
     */
    private function filterByScore(array $results): array
    {
        $minScore = (float) config('ai.retrieval.min_score', 0.0);

        if ($minScore <= 0.0) {
            return $results;
        }

        return array_values(array_filter(
            $results,
            fn (array $result) => (float) ($result['score'] ?? 0.0) >= $minScore,
        ));
    }

    // -------------------------------------------------------------------------
    // Qdrant
    // -------------------------------------------------------------------------

    private function qdrantEnabled(): bool
    {
        return $this->qdrantConfig()['url'] !== '';
    }

    /**
     * Resolve the Qdrant connection.
     *
     * Admin → Integrations → Qdrant is authoritative when configured, with the
     * .env values as a fallback. Reading the DB here keeps the UI and the
     * runtime in sync instead of silently ignoring what the admin saved.
     *
     * @return array{url: string, api_key: string}
     */
    private function qdrantConfig(): array
    {
        $url = trim((string) config('services.qdrant.url', ''));
        $apiKey = (string) config('services.qdrant.api_key', '');

        try {
            $credentials = CredentialResolver::system()->qdrant();

            if ($credentials && $credentials->has('url')) {
                $url = trim((string) $credentials->url());
                $apiKey = (string) ($credentials->apiKey() ?: $apiKey);
            }
        } catch (\Throwable) {
            // Integrations table unavailable (fresh install, CLI) — use env.
        }

        return ['url' => rtrim($url, '/'), 'api_key' => $apiKey];
    }

    private function qdrantClient(): PendingRequest
    {
        $config = $this->qdrantConfig();

        $client = Http::baseUrl($config['url'])
            ->timeout(10)
            ->retry(2, 300);

        if ($config['api_key'] !== '') {
            $client = $client->withHeaders(['api-key' => $config['api_key']]);
        }

        return $client;
    }

    private function qdrantUpsert(AiKbChunk $chunk, array $embedding): void
    {
        try {
            $this->ensureQdrantCollection(count($embedding));

            $this->qdrantClient()->put('/collections/'.self::QDRANT_COLLECTION.'/points', [
                'points' => [[
                    'id' => $chunk->id,
                    'vector' => $embedding,
                    'payload' => [
                        'kb_id' => $chunk->kb_id,
                        'document_id' => $chunk->document_id,
                        'chunk_id' => $chunk->id,
                    ],
                ]],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Qdrant upsert failed, embedding stored in MySQL only', ['error' => $e->getMessage()]);
        }
    }

    private function qdrantSearch(int $kbId, array $queryEmbedding, int $topK): array
    {
        try {
            $resp = $this->qdrantClient()->post('/collections/'.self::QDRANT_COLLECTION.'/points/search', [
                'vector' => $queryEmbedding,
                'limit' => $topK,
                'filter' => [
                    'must' => [['key' => 'kb_id', 'match' => ['value' => $kbId]]],
                ],
                'with_payload' => true,
            ]);

            if (! $resp->successful()) {
                return [];
            }

            $chunkIds = array_column($resp->json('result', []), 'id');
            if (empty($chunkIds)) {
                return [];
            }

            // Re-apply the kb scope on the relational fetch too: Qdrant is a
            // shared collection, and this keeps a malformed point from ever
            // resolving to another base's chunk.
            $chunks = AiKbChunk::where('kb_id', $kbId)->whereIn('id', $chunkIds)->get()->keyBy('id');
            $results = [];
            foreach ($resp->json('result', []) as $hit) {
                $chunk = $chunks->get($hit['id']);
                if ($chunk) {
                    $results[] = ['chunk' => $chunk, 'score' => $hit['score']];
                }
            }

            return $results;
        } catch (\Throwable $e) {
            Log::warning('Qdrant search failed, falling back to MySQL', ['error' => $e->getMessage()]);

            return [];
        }
    }

    private function ensureQdrantCollection(int $dimensions): void
    {
        $client = $this->qdrantClient();
        $check = $client->get('/collections/'.self::QDRANT_COLLECTION);
        if ($check->successful()) {
            return;
        }

        $client->put('/collections/'.self::QDRANT_COLLECTION, [
            'vectors' => ['size' => $dimensions, 'distance' => 'Cosine'],
        ]);
    }

    // -------------------------------------------------------------------------
    // MySQL fallback
    // -------------------------------------------------------------------------

    /**
     * @param  list<string>  $terms  Query terms for the keyword boost
     */
    private function mysqlSearch(int $kbId, array $queryEmbedding, int $topK, array $terms = [], float $boost = 0.0): array
    {
        $topK = max(1, $topK);

        /**
         * Scan in batches and keep only the best `topK * 4` candidates.
         *
         * Loading every chunk into memory was the old behaviour: a 50k-chunk
         * knowledge base materialised 50k models plus 50k decoded vectors on
         * every chat message. Chunking keeps memory flat; the small candidate
         * buffer is trimmed whenever it grows.
         *
         * The blend is applied while scanning, so a chunk that only wins on
         * keywords is not discarded before it can be re-ranked.
         *
         * @var array<int, array{chunk: AiKbChunk, score: float}> $candidates
         */
        $candidates = [];

        AiKbChunk::where('kb_id', $kbId)
            ->whereNotNull('embedding')
            ->select(['id', 'kb_id', 'document_id', 'ord', 'content', 'tokens', 'embedding'])
            ->chunkById(500, function ($chunks) use ($queryEmbedding, $topK, $terms, $boost, &$candidates): void {
                foreach ($chunks as $chunk) {
                    $score = $this->cosine($queryEmbedding, $this->unpackEmbedding($chunk->embedding ?? ''));

                    $candidates[] = [
                        'chunk' => $chunk,
                        'score' => $this->boostScore($score, (string) $chunk->content, $terms, $boost),
                    ];

                    if (count($candidates) >= $topK * 4) {
                        $candidates = $this->topCandidates($candidates, $topK);
                    }
                }
            });

        return $this->topCandidates($candidates, $topK);
    }

    /**
     * Trim a candidate list to the highest scores.
     *
     * @param  array<int, array{chunk: AiKbChunk, score: float}>  $candidates
     * @return array<int, array{chunk: AiKbChunk, score: float}>
     */
    private function topCandidates(array $candidates, int $topK): array
    {
        usort($candidates, fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($candidates, 0, $topK);
    }

    // -------------------------------------------------------------------------
    // Hybrid search (semantic + keyword)
    // -------------------------------------------------------------------------

    /**
     * Apply the keyword boost to already-scored results (Qdrant path).
     *
     * @param  array<int, array{chunk: AiKbChunk, score: float}>  $results
     * @param  list<string>  $terms
     * @return array<int, array{chunk: AiKbChunk, score: float}>
     */
    private function applyKeywordBoost(array $results, array $terms, float $boost): array
    {
        if ($terms === [] || $boost <= 0.0) {
            return $results;
        }

        foreach ($results as $result) {
            $result['score'] = $this->boostScore(
                (float) ($result['score'] ?? 0.0),
                (string) ($result['chunk']->content ?? ''),
                $terms,
                $boost,
            );
        }

        return $results;
    }

    /**
     * Blend a cosine score with lexical overlap.
     *
     * The boost only ever raises a score (`cos + boost * overlap * (1 - cos)`),
     * so the relevance floor keeps its meaning: a semantically relevant chunk
     * is never pushed below `ai.retrieval.min_score` because it lacks a
     * literal keyword, while an exact keyword hit can clear the floor on its
     * own. Result stays within 0..1.
     *
     * @param  list<string>  $terms
     */
    private function boostScore(float $cosine, string $content, array $terms, float $boost): float
    {
        $cosine = max(0.0, min(1.0, $cosine));

        if ($terms === [] || $boost <= 0.0) {
            return $cosine;
        }

        $overlap = $this->keywordScore($content, $terms);

        if ($overlap <= 0.0) {
            return $cosine;
        }

        return min(1.0, $cosine + $boost * $overlap * (1.0 - $cosine));
    }

    /**
     * Fraction of query terms that appear in the chunk (0..1).
     *
     * @param  list<string>  $terms
     */
    private function keywordScore(string $content, array $terms): float
    {
        if ($terms === []) {
            return 0.0;
        }

        $haystack = mb_strtolower($content);
        $matches = 0;

        foreach ($terms as $term) {
            if (mb_strpos($haystack, $term) !== false) {
                $matches++;
            }
        }

        return $matches / count($terms);
    }

    /**
     * Distinct, meaningful terms of a query.
     *
     * Unicode-aware so Arabic and other non-Latin questions tokenize too;
     * stopwords and very short tokens are dropped because they match nearly
     * every chunk. Capped so a pasted paragraph cannot dominate the scan.
     *
     * @return list<string>
     */
    private function queryTerms(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 3 || in_array($part, self::STOPWORDS, true)) {
                continue;
            }

            $terms[$part] = true;

            if (count($terms) >= 24) {
                break;
            }
        }

        return array_keys($terms);
    }

    /**
     * Resolve the configured boost, or 0 when there is nothing to match.
     *
     * @param  list<string>  $terms
     */
    private function keywordBoost(array $terms): float
    {
        if ($terms === []) {
            return 0.0;
        }

        return max(0.0, min(1.0, (float) config('ai.retrieval.keyword_boost', 0.3)));
    }

    private function unpackEmbedding(string $json): array
    {
        if (empty($json)) {
            return [];
        }
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function cosine(array $a, array $b): float
    {
        if (count($a) !== count($b) || empty($a)) {
            return 0.0;
        }
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $v) {
            $dot += $v * $b[$i];
            $na += $v * $v;
            $nb += $b[$i] * $b[$i];
        }
        $denom = sqrt($na) * sqrt($nb);

        return $denom > 0 ? $dot / $denom : 0.0;
    }
}
