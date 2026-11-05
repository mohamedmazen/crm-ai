<?php

namespace Tests\Unit\Cache;

use App\Support\Cache\TenantCache;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TenantCacheTest extends TestCase
{
    #[Test]
    public function keys_are_namespaced_by_workspace(): void
    {
        $this->assertSame('tenant:42:leads.stats', TenantCache::for(42)->key('leads.stats'));
    }

    #[Test]
    public function a_value_written_by_one_workspace_is_invisible_to_another(): void
    {
        TenantCache::for(1)->put('dashboard.stats', ['leads' => 10], 60);

        $this->assertSame(['leads' => 10], TenantCache::for(1)->get('dashboard.stats'));
        $this->assertNull(TenantCache::for(2)->get('dashboard.stats'));
        $this->assertFalse(TenantCache::for(2)->has('dashboard.stats'));
    }

    #[Test]
    public function remember_never_reuses_another_workspaces_value(): void
    {
        TenantCache::for(1)->remember('report', 60, fn () => 'tenant-one');

        $value = TenantCache::for(2)->remember('report', 60, fn () => 'tenant-two');

        $this->assertSame('tenant-two', $value);
        $this->assertSame('tenant-one', TenantCache::for(1)->get('report'));
    }

    #[Test]
    public function add_is_scoped_to_the_workspace(): void
    {
        $this->assertTrue(TenantCache::for(1)->add('lock', 'one', 60));
        $this->assertFalse(TenantCache::for(1)->add('lock', 'again', 60));

        // The key already taken by workspace 1 must not block workspace 2.
        $this->assertTrue(TenantCache::for(2)->add('lock', 'two', 60));
    }

    #[Test]
    public function forget_only_removes_the_current_workspaces_entry(): void
    {
        TenantCache::for(1)->put('shared-key', 'one', 60);
        TenantCache::for(2)->put('shared-key', 'two', 60);

        TenantCache::for(1)->forget('shared-key');

        $this->assertNull(TenantCache::for(1)->get('shared-key'));
        $this->assertSame('two', TenantCache::for(2)->get('shared-key'));
    }

    #[Test]
    public function values_are_stored_under_the_prefixed_key(): void
    {
        TenantCache::for(7)->put('greeting', 'hello', 60);

        $this->assertSame('hello', Cache::get('tenant:7:greeting'));
    }

    #[Test]
    public function it_rejects_a_missing_workspace_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TenantCache::for(0);
    }

    #[Test]
    public function it_rejects_a_negative_workspace_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TenantCache::for(-3);
    }
}
