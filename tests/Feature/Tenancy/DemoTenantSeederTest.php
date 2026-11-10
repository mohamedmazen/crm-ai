<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use Database\Seeders\DemoBusinessSeeder;
use Database\Seeders\DemoProSeeder;
use Database\Seeders\DemoStarterSeeder;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The three tier seeders run back-to-back on a fresh install, so any demo value
 * that is globally unique (rather than scoped to a workspace) collides the
 * moment the second tier is seeded.
 */
class DemoTenantSeederTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_EMAILS = [
        'starter@aydra.demo',
        'pro@aydra.demo',
        'business@aydra.demo',
    ];

    public function test_demo_tiers_seed_together_without_cross_tenant_collisions(): void
    {
        $seeders = [
            PlanSeeder::class,
            DemoStarterSeeder::class,
            DemoProSeeder::class,
            DemoBusinessSeeder::class,
        ];

        $this->seed($seeders);

        $this->assertDemoDataIsIsolated();

        // The seeders promise to be re-runnable against an existing database.
        // The Pro tier is the one that previously collided, and it runs after
        // Starter, so a second pass proves the shared helpers are idempotent.
        $this->seed(DemoProSeeder::class);

        $this->assertDemoDataIsIsolated();
    }

    private function assertDemoDataIsIsolated(): void
    {
        $workspaceIds = User::whereIn('email', self::ADMIN_EMAILS)
            ->whereNotNull('workspace_id')
            ->pluck('workspace_id')
            ->unique()
            ->sort()
            ->values()
            ->all();

        $this->assertCount(3, $workspaceIds, 'Each demo tier should own its own workspace.');

        $accountTotals = DB::table('social_media_accounts')
            ->selectRaw('workspace_id, COUNT(*) as total')
            ->groupBy('workspace_id')
            ->pluck('total', 'workspace_id');

        foreach ($workspaceIds as $workspaceId) {
            $this->assertSame(
                2,
                (int) $accountTotals[$workspaceId],
                "Workspace {$workspaceId} should seed exactly two social accounts.",
            );
        }

        // Booking pages live at /b/{slug} with no tenant in the path, so their
        // slugs are globally unique and every tier needs a distinct pair.
        $slugs = DB::table('meeting_types')->pluck('slug');

        $this->assertCount(6, $slugs);
        $this->assertSame(
            $slugs->count(),
            $slugs->unique()->count(),
            'Meeting-type slugs must stay globally unique across demo tiers.',
        );

        $this->assertSame(9, DB::table('social_media_posts')->count());

        foreach (DB::table('social_media_posts')->get(['id', 'workspace_id', 'target_accounts']) as $post) {
            $targets = json_decode((string) $post->target_accounts, true) ?: [];

            $this->assertNotEmpty($targets, "Post {$post->id} should target at least one account.");

            $owners = DB::table('social_media_accounts')
                ->whereIn('id', $targets)
                ->pluck('workspace_id')
                ->unique()
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();

            $this->assertSame(
                [(int) $post->workspace_id],
                $owners,
                "Post {$post->id} must only target accounts from its own workspace.",
            );
        }

        // Demo tenants connect social channels, never social *login* links:
        // that table is unique on (provider, provider_id) across all users.
        $this->assertSame(
            0,
            DB::table('social_accounts')->count(),
            'Demo seeding must not write demo rows into the social login table.',
        );
    }
}
