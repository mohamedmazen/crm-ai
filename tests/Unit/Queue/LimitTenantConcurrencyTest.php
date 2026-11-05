<?php

namespace Tests\Unit\Queue;

use App\Modules\AI\Jobs\IndexDocumentJob;
use App\Modules\Broadcasting\Jobs\SendCampaignMessageJob;
use App\Modules\Ecommerce\Jobs\BackfillStoreOrdersJob;
use App\Modules\Ecommerce\Jobs\SyncStoreCustomersJob;
use App\Modules\Ecommerce\Jobs\SyncStoreProductsJob;
use App\Modules\Social\Jobs\PublishSocialPostJob;
use App\Support\Queue\Middleware\LimitTenantConcurrency;
use App\Support\Queue\TenantAwareJob;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Support\FakeTenantJob;
use Tests\TestCase;

class LimitTenantConcurrencyTest extends TestCase
{
    #[Test]
    public function it_caps_how_many_jobs_one_workspace_runs_at_once(): void
    {
        $middleware = new LimitTenantConcurrency(
            maxConcurrent: 2,
            bucket: 'unit-cap',
            lockSeconds: 60,
            releaseAfter: 7,
        );

        $first = new FakeTenantJob(9101);
        $second = new FakeTenantJob(9101);
        $third = new FakeTenantJob(9101);

        // Hold both slots by nesting the callbacks: while the outer job runs,
        // the second job takes the remaining slot and the third has none left.
        $middleware->handle($first, function () use ($middleware, $first, $second, $third) {
            $middleware->handle($second, function () use ($middleware, $second, $third) {
                $middleware->handle($third, fn () => $third->handle());

                $second->handle();
            });

            $first->handle();
        });

        $this->assertSame(1, $first->handled);
        $this->assertSame(1, $second->handled);
        $this->assertSame(0, $third->handled, 'A third concurrent job must not run.');
        $this->assertSame(7, $third->releasedAfter, 'The rejected job must be released, not dropped.');

        // Once the running jobs finish their slots are free again.
        $retry = new FakeTenantJob(9101);
        $middleware->handle($retry, fn () => $retry->handle());

        $this->assertSame(1, $retry->handled);
    }

    #[Test]
    public function one_workspace_filling_its_slots_does_not_block_another(): void
    {
        $middleware = new LimitTenantConcurrency(maxConcurrent: 1, bucket: 'unit-isolation', lockSeconds: 60);

        $busy = new FakeTenantJob(9201);
        $other = new FakeTenantJob(9202);

        $middleware->handle($busy, function () use ($middleware, $other) {
            $middleware->handle($other, fn () => $other->handle());
        });

        $this->assertSame(1, $other->handled);
        $this->assertNull($other->releasedAfter);
    }

    #[Test]
    public function jobs_without_a_workspace_bypass_the_cap(): void
    {
        $middleware = new LimitTenantConcurrency(maxConcurrent: 1, bucket: 'unit-null', lockSeconds: 60);

        $holder = new FakeTenantJob(9301);
        $ownerless = new FakeTenantJob(null);

        $middleware->handle($holder, function () use ($middleware, $holder, $ownerless) {
            $middleware->handle($ownerless, fn () => $ownerless->handle());

            $holder->handle();
        });

        $this->assertSame(1, $ownerless->handled);
        $this->assertNull($ownerless->releasedAfter);
    }

    #[Test]
    public function a_disabled_cap_lets_everything_through(): void
    {
        $middleware = new LimitTenantConcurrency(maxConcurrent: 0, bucket: 'unit-disabled', lockSeconds: 60);

        $holder = new FakeTenantJob(9401);
        $other = new FakeTenantJob(9401);

        $middleware->handle($holder, function () use ($middleware, $holder, $other) {
            $middleware->handle($other, fn () => $other->handle());

            $holder->handle();
        });

        $this->assertSame(1, $other->handled);
        $this->assertNull($other->releasedAfter);
    }

    #[Test]
    public function a_failing_job_still_frees_its_slot(): void
    {
        $middleware = new LimitTenantConcurrency(maxConcurrent: 1, bucket: 'unit-failure', lockSeconds: 60);

        $failing = new FakeTenantJob(9501);

        try {
            $middleware->handle($failing, fn () => throw new RuntimeException('worker died'));
        } catch (RuntimeException) {
            // Expected: the slot must still be released by the finally block.
        }

        $next = new FakeTenantJob(9501);
        $middleware->handle($next, fn () => $next->handle());

        $this->assertSame(1, $next->handled);
    }

    #[Test]
    public function tenant_jobs_declare_a_concurrency_limit(): void
    {
        $jobs = [
            new SendCampaignMessageJob(1, 1),
            new PublishSocialPostJob(1),
            new IndexDocumentJob(1),
            new SyncStoreProductsJob(1),
            new SyncStoreCustomersJob(1),
            new BackfillStoreOrdersJob(1),
        ];

        foreach ($jobs as $job) {
            $this->assertInstanceOf(TenantAwareJob::class, $job);

            $concurrency = array_filter(
                $job->middleware(),
                fn (object $middleware) => $middleware instanceof LimitTenantConcurrency,
            );

            $this->assertCount(1, $concurrency, $job::class.' must declare a tenant concurrency limit.');
        }
    }
}
