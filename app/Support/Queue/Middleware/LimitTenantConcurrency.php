<?php

namespace App\Support\Queue\Middleware;

use App\Support\Cache\TenantCache;
use App\Support\Queue\TenantAwareJob;
use Closure;

/**
 * Caps how many jobs a single workspace may run at the same time.
 *
 * Without this, one tenant (say, a full e-commerce catalogue sync or a whole
 * knowledge-base re-index) can occupy every worker on a shared queue and stall
 * everyone else. Slots are contention-free cache locks scoped to the workspace,
 * so the cap is shared by every worker process and every server.
 *
 * When all slots are busy the job is released back onto the queue instead of
 * holding a worker hostage. `lockSeconds` must comfortably exceed the job's
 * `$timeout`, otherwise a killed worker would leave the slot locked until the
 * TTL expires.
 */
class LimitTenantConcurrency
{
    public function __construct(
        private readonly int $maxConcurrent,
        private readonly string $bucket,
        private readonly int $lockSeconds,
        private readonly int $releaseAfter = 10,
    ) {}

    public function handle(object $job, Closure $next): mixed
    {
        $workspaceId = $job instanceof TenantAwareJob ? $job->tenantWorkspaceId() : null;

        if ($workspaceId === null || $this->maxConcurrent <= 0) {
            return $next($job);
        }

        $cache = TenantCache::for($workspaceId);
        $lock = null;

        for ($slot = 0; $slot < $this->maxConcurrent; $slot++) {
            $candidate = $cache->lock($this->lockKey($slot), $this->lockSeconds);

            if ($candidate->get()) {
                $lock = $candidate;

                break;
            }
        }

        if ($lock === null) {
            if (method_exists($job, 'release')) {
                $job->release($this->releaseAfter);
            }

            return null;
        }

        try {
            return $next($job);
        } finally {
            $lock->release();
        }
    }

    private function lockKey(int $slot): string
    {
        return 'queue-concurrency:'.$this->bucket.':'.$slot;
    }
}
