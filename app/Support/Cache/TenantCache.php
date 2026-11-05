<?php

namespace App\Support\Cache;

use Closure;
use DateInterval;
use DateTimeInterface;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Workspace-scoped cache access.
 *
 * Every key written through this class is prefixed with `tenant:{workspaceId}:`,
 * so two workspaces can never read each other's cached values even when they use
 * the same logical key (e.g. `dashboard.stats`).
 *
 * Use this for anything derived from tenant data. Platform-wide values (branding,
 * translations, storage settings, license state) must stay on the `Cache` facade:
 * they are identical for every workspace and prefixed per tenant would only
 * fragment them. The `TenantCacheUsageTest` enforces that split.
 *
 * @example
 *   TenantCache::for($workspace->id)->remember('dashboard.stats', 300, fn () => ...);
 */
final class TenantCache
{
    private function __construct(
        private readonly Repository $repository,
        private readonly int $workspaceId,
    ) {}

    public static function for(int $workspaceId): self
    {
        if ($workspaceId <= 0) {
            throw new InvalidArgumentException('TenantCache requires a positive workspace id.');
        }

        return new self(Cache::store(), $workspaceId);
    }

    public function workspaceId(): int
    {
        return $this->workspaceId;
    }

    /**
     * Build the fully qualified cache key for this workspace.
     */
    public function key(string $key): string
    {
        return 'tenant:'.$this->workspaceId.':'.$key;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->repository->get($this->key($key), $default);
    }

    public function put(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->repository->put($this->key($key), $value, $ttl);
    }

    /**
     * Store the value only when this workspace has no value for the key yet.
     */
    public function add(string $key, mixed $value, DateTimeInterface|DateInterval|int|null $ttl = null): bool
    {
        return $this->repository->add($this->key($key), $value, $ttl);
    }

    public function has(string $key): bool
    {
        return $this->repository->has($this->key($key));
    }

    public function remember(string $key, DateTimeInterface|DateInterval|int|null $ttl, Closure $callback): mixed
    {
        return $this->repository->remember($this->key($key), $ttl, $callback);
    }

    public function rememberForever(string $key, Closure $callback): mixed
    {
        return $this->repository->rememberForever($this->key($key), $callback);
    }

    public function forget(string $key): bool
    {
        return $this->repository->forget($this->key($key));
    }

    /**
     * Acquire an atomic lock scoped to this workspace.
     */
    public function lock(string $key, int $seconds = 0, ?string $owner = null): Lock
    {
        return Cache::lock($this->key($key), $seconds, $owner);
    }
}
