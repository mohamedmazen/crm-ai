<?php

namespace Tests\Unit\Queue;

use Illuminate\Queue\Middleware\RateLimited;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\FakeTenantJob;
use Tests\TestCase;

class TenantRateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.tenant_limits.messages_per_minute' => 2,
            'queue.tenant_limits.social_posts_per_minute' => 1,
        ]);
    }

    private function process(RateLimited $middleware, FakeTenantJob $job): void
    {
        $middleware->handle($job, fn () => $job->handle());
    }

    #[Test]
    public function a_workspace_cannot_exceed_its_message_rate(): void
    {
        $middleware = new RateLimited('tenant-broadcast');
        $workspaceId = 9601;

        $first = new FakeTenantJob($workspaceId);
        $second = new FakeTenantJob($workspaceId);
        $third = new FakeTenantJob($workspaceId);

        $this->process($middleware, $first);
        $this->process($middleware, $second);
        $this->process($middleware, $third);

        $this->assertSame(1, $first->handled);
        $this->assertSame(1, $second->handled);
        $this->assertSame(0, $third->handled, 'The third job must not run within the same minute.');
        $this->assertNotNull($third->releasedAfter, 'A throttled job must be released back onto the queue.');
        $this->assertGreaterThan(0, $third->releasedAfter);
    }

    #[Test]
    public function one_workspace_exhausting_its_budget_leaves_other_workspaces_alone(): void
    {
        $middleware = new RateLimited('tenant-broadcast');

        $exhausted = new FakeTenantJob(9701);
        $exhaustedAgain = new FakeTenantJob(9701);
        $rejected = new FakeTenantJob(9701);
        $unaffected = new FakeTenantJob(9702);

        $this->process($middleware, $exhausted);
        $this->process($middleware, $exhaustedAgain);
        $this->process($middleware, $rejected);

        $this->process($middleware, $unaffected);

        $this->assertSame(1, $exhausted->handled);
        $this->assertSame(1, $exhaustedAgain->handled);
        $this->assertSame(0, $rejected->handled);
        $this->assertSame(1, $unaffected->handled);
        $this->assertNull($unaffected->releasedAfter);
    }

    #[Test]
    public function the_broadcast_and_social_budgets_are_independent(): void
    {
        $broadcast = new RateLimited('tenant-broadcast');
        $social = new RateLimited('tenant-social');
        $workspaceId = 9801;

        foreach (range(1, 2) as $attempt) {
            $this->process($broadcast, new FakeTenantJob($workspaceId));
        }

        // Broadcast budget is now exhausted, but social allows one post.
        $post = new FakeTenantJob($workspaceId);
        $this->process($social, $post);

        $secondPost = new FakeTenantJob($workspaceId);
        $this->process($social, $secondPost);

        $this->assertSame(1, $post->handled);
        $this->assertSame(0, $secondPost->handled);
        $this->assertNotNull($secondPost->releasedAfter);
    }

    #[Test]
    public function jobs_without_a_workspace_are_not_rate_limited(): void
    {
        $middleware = new RateLimited('tenant-broadcast');

        foreach (range(1, 5) as $attempt) {
            $job = new FakeTenantJob(null);
            $this->process($middleware, $job);

            $this->assertSame(1, $job->handled);
            $this->assertNull($job->releasedAfter);
        }
    }
}
