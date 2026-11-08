<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PlanController;
use App\Models\Currency;
use App\Models\Plan;
use App\Services\MediaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanLimitsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_plan_update_persists_every_limit_key(): void
    {
        Currency::firstOrCreate(
            ['code' => 'USD'],
            ['symbol' => '$', 'decimals' => 2, 'exchange_rate' => 1, 'is_default' => true, 'enabled' => true]
        );

        $plan = Plan::factory()->create(['currency_code' => 'USD']);

        $limits = [];
        foreach (PlanController::limitKeys() as $index => $key) {
            $limits[$key] = $index + 1;
        }

        $this->actingAs($this->createSuperAdmin(), 'admin')
            ->put(route('admin.plans.update', $plan), [
                'name' => $plan->name,
                'slug' => $plan->slug,
                'currency_code' => 'USD',
                'monthly_price_cents' => 1000,
                'limits' => $limits,
            ])
            ->assertRedirect(route('admin.plans.index'));

        $plan->refresh();

        $stored = $plan->limits;
        ksort($stored);
        ksort($limits);

        $this->assertCount(count(PlanController::limitKeys()), $limits);
        $this->assertSame($limits, $stored);
    }

    public function test_media_quota_reads_storage_limit_in_megabytes(): void
    {
        $plan = Plan::factory()->create(['limits' => ['storage' => 5120]]);
        $ctx = $this->createWorkspaceContext();
        $this->attachPlanToClient($ctx['client'], $plan);

        $quota = app(MediaService::class)->quotaBytes($ctx['user']->fresh());

        $this->assertSame(5120 * 1024 * 1024, $quota);
    }

    public function test_media_quota_is_unlimited_when_storage_limit_is_null(): void
    {
        $plan = Plan::factory()->create(['limits' => ['storage' => null]]);
        $ctx = $this->createWorkspaceContext();
        $this->attachPlanToClient($ctx['client'], $plan);

        $this->assertSame(PHP_INT_MAX, app(MediaService::class)->quotaBytes($ctx['user']->fresh()));
    }
}
