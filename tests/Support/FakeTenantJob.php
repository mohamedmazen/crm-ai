<?php

namespace Tests\Support;

use App\Support\Queue\TenantAwareJob;

/**
 * Minimal queued-job stand-in for exercising job middleware in isolation.
 *
 * It records what the pipeline did to it instead of touching a real queue, so
 * middleware behaviour can be asserted without the database, bus or workers.
 */
class FakeTenantJob implements TenantAwareJob
{
    public int $handled = 0;

    public ?int $releasedAfter = null;

    public function __construct(private readonly ?int $workspaceId) {}

    public function tenantWorkspaceId(): ?int
    {
        return $this->workspaceId;
    }

    public function release(int $delay = 0): void
    {
        $this->releasedAfter = $delay;
    }

    public function handle(): void
    {
        $this->handled++;
    }
}
