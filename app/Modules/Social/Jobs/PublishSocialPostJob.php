<?php

namespace App\Modules\Social\Jobs;

use App\Modules\Social\Models\SocialPost;
use App\Modules\Social\Services\SocialPublisher;
use App\Support\Queue\Middleware\LimitTenantConcurrency;
use App\Support\Queue\TenantAwareJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

class PublishSocialPostJob implements ShouldQueue, TenantAwareJob
{
    use Queueable;

    public int $tries = 3;

    // Multi-image/video publishes make several sequential API calls; 120s was
    // short enough for the worker to kill legitimate runs mid-publish.
    public int $timeout = 300;

    /**
     * Workspace that owns the post. Null only for jobs queued before this
     * field existed; then it is resolved from the post itself.
     */
    public ?int $workspaceId = null;

    public function __construct(
        public readonly int $postId,
        ?int $workspaceId = null,
    ) {
        $this->workspaceId = $workspaceId;
    }

    public function tenantWorkspaceId(): ?int
    {
        return $this->workspaceId ?? SocialPost::find($this->postId)?->workspace_id;
    }

    /**
     * Publishing is slow and calls third-party APIs: one post per workspace at
     * a time, and no more than the configured posts per minute.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            new RateLimited('tenant-social'),
            new LimitTenantConcurrency(
                maxConcurrent: (int) config('queue.tenant_limits.concurrent_social_jobs', 1),
                bucket: 'social',
                lockSeconds: 360,
            ),
        ];
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        return [60, 180];
    }

    public function handle(SocialPublisher $publisher): void
    {
        $post = SocialPost::find($this->postId);

        // Post deleted, already fully published, or reverted to draft — nothing to do.
        if (! $post || in_array($post->status, ['published', 'draft'], true)) {
            return;
        }

        $publisher->publish($post, finalAttempt: $this->attempts() >= $this->tries);
    }

    /**
     * All retries exhausted (or the job timed out / crashed): never leave the
     * post stuck in 'publishing' — the scheduler only re-picks 'scheduled' posts.
     */
    public function failed(?\Throwable $exception): void
    {
        $post = SocialPost::find($this->postId);

        if ($post && in_array($post->status, ['publishing', 'scheduled'], true)) {
            $post->update(['status' => 'failed']);
        }

        Log::error('Social post publish job failed permanently', [
            'post_id' => $this->postId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
