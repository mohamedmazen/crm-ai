<?php

namespace Tests\Unit\Queue;

use App\Modules\Automation\Jobs\ScanWorkspaceTimeTriggersJob;
use App\Modules\Leads\Jobs\DecayLeadScoresJob;
use App\Modules\Leads\Jobs\RescoreWorkspaceLeadsJob;
use App\Modules\Leads\Jobs\ScrapeLeadsJob;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * A job that defines its own `$timeout` is allowed to run that long. If
 * `retry_after` is smaller, the queue makes the still-running job visible again
 * and a second worker picks it up — the same workspace gets scored twice.
 *
 * Lead scoring and automation scans are the longest runners (900s), so the
 * connection retry window must stay above them.
 */
class QueueRetryAfterTest extends TestCase
{
    /** @var array<int, class-string> */
    private const LONG_RUNNING_JOBS = [
        ScrapeLeadsJob::class,
        RescoreWorkspaceLeadsJob::class,
        DecayLeadScoresJob::class,
        ScanWorkspaceTimeTriggersJob::class,
    ];

    #[Test]
    public function queue_retry_after_exceeds_the_longest_job_timeout(): void
    {
        $longest = 0;

        foreach (self::LONG_RUNNING_JOBS as $job) {
            $timeout = (int) ((new ReflectionClass($job))->getDefaultProperties()['timeout'] ?? 0);
            $longest = max($longest, $timeout);
        }

        $this->assertGreaterThan(0, $longest, 'Expected at least one long-running job.');

        foreach (['redis', 'database'] as $connection) {
            $retryAfter = (int) config("queue.connections.{$connection}.retry_after");

            $this->assertGreaterThan(
                $longest,
                $retryAfter,
                "queue.connections.{$connection}.retry_after must exceed the longest job timeout ({$longest}s).",
            );
        }
    }
}
