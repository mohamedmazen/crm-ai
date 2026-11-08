<?php

namespace App\Console\Commands;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\AI\Models\AiKbDocument;
use Illuminate\Console\Command;

/**
 * Re-index URL and sitemap knowledge-base documents.
 *
 * Re-indexing sends If-None-Match / If-Modified-Since, so an unchanged page
 * costs one conditional request and no embedding calls — only pages whose
 * content actually changed are re-chunked and re-embedded.
 */
class RefreshKnowledgeBaseUrlsCommand extends Command
{
    protected $signature = 'ai:refresh-knowledge-bases {--hours=24 : Only documents last indexed before this many hours ago}';

    protected $description = 'Queue re-indexing for URL and sitemap knowledge-base documents';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $cutoff = now()->subHours($hours);
        $dispatched = 0;

        AiKbDocument::query()
            ->with('knowledgeBase')
            ->whereIn('source_type', ['url', 'sitemap'])
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_indexed_at')
                    ->orWhere('last_indexed_at', '<=', $cutoff);
            })
            ->chunkById(100, function ($documents) use (&$dispatched): void {
                foreach ($documents as $document) {
                    $workspaceId = $document->knowledgeBase?->workspace_id;

                    if (! $workspaceId) {
                        continue;
                    }

                    $document->update(['status' => 'pending']);
                    IndexDocumentJob::dispatch($document->id, (int) $workspaceId)->onQueue('ai');
                    $dispatched++;
                }
            });

        $this->info("Queued {$dispatched} knowledge-base document(s) for refresh.");

        return self::SUCCESS;
    }
}
