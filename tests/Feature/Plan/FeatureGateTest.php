<?php

namespace Tests\Feature\Plan;

use App\Models\Plan;
use App\Services\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeatureGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_true_flag_enables_the_feature(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['tasks' => true]]);

        $this->assertTrue(FeatureGate::forPlan($plan)->enabled('tasks'));
    }

    public function test_false_flag_disables_the_feature(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['tasks' => false]]);

        $this->assertFalse(FeatureGate::forPlan($plan)->enabled('tasks'));
    }

    public function test_missing_flag_is_disabled(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['tasks' => true]]);

        $this->assertFalse(FeatureGate::forPlan($plan)->enabled('multi_pipeline'));
    }

    public function test_unknown_feature_is_disabled(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['tasks' => true]]);

        $this->assertFalse(FeatureGate::forPlan($plan)->enabled('teleportation'));
    }

    public function test_null_flag_enables_the_feature_without_a_limit(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['custom_fields' => null]]);
        $gate = FeatureGate::forPlan($plan);

        $this->assertTrue($gate->enabled('custom_fields'));
        $this->assertNull($gate->limit('custom_fields'));
    }

    public function test_zero_flag_disables_the_feature(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['sequences' => 0]]);

        $this->assertFalse(FeatureGate::forPlan($plan)->enabled('sequences'));
    }

    public function test_numeric_flag_enables_the_feature_and_exposes_its_cap(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['forms' => 10]]);
        $gate = FeatureGate::forPlan($plan);

        $this->assertTrue($gate->enabled('forms'));
        $this->assertSame(10, $gate->limit('forms'));
    }

    public function test_within_limit_respects_the_cap(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['forms' => 5]]);
        $gate = FeatureGate::forPlan($plan);

        $this->assertTrue($gate->withinLimit('forms', 4));
        $this->assertFalse($gate->withinLimit('forms', 5));
        $this->assertFalse($gate->withinLimit('forms', 6));
    }

    public function test_within_limit_is_always_true_for_unlimited_features(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['forms' => null]]);

        $this->assertTrue(FeatureGate::forPlan($plan)->withinLimit('forms', 5000));
    }

    public function test_within_limit_is_false_when_the_feature_is_disabled(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => ['forms' => false]]);

        $this->assertFalse(FeatureGate::forPlan($plan)->withinLimit('forms', 0));
    }

    public function test_plan_without_flags_disables_every_gate(): void
    {
        $plan = Plan::factory()->create(['feature_flags' => null]);
        $gate = FeatureGate::forPlan($plan);

        $this->assertFalse($gate->enabled('tasks'));
        $this->assertNull($gate->limit('tasks'));
    }

    public function test_no_plan_disables_every_gate(): void
    {
        $gate = FeatureGate::forPlan(null);

        $this->assertNull($gate->plan());
        $this->assertFalse($gate->enabled('tasks'));
        $this->assertNull($gate->limit('tasks'));
        $this->assertFalse($gate->withinLimit('tasks', 0));
    }

    public function test_white_label_falls_back_to_the_legacy_column(): void
    {
        $plan = Plan::factory()->create([
            'feature_flags' => ['tasks' => true],
            'white_label_enabled' => true,
        ]);

        $this->assertTrue(FeatureGate::forPlan($plan)->enabled('white_label'));
    }

    public function test_explicit_white_label_flag_overrides_the_legacy_column(): void
    {
        $plan = Plan::factory()->create([
            'feature_flags' => ['white_label' => false],
            'white_label_enabled' => true,
        ]);

        $this->assertFalse(FeatureGate::forPlan($plan)->enabled('white_label'));
    }

    public function test_all_returns_every_configured_gate(): void
    {
        $plan = Plan::factory()->create([
            'feature_flags' => [
                'tasks' => true,
                'companies' => false,
                'forms' => 10,
                'custom_fields' => null,
            ],
        ]);

        $features = FeatureGate::forPlan($plan)->all();

        $this->assertSame(array_keys(config('features.labels')), array_keys($features));
        $this->assertTrue($features['tasks']);
        $this->assertFalse($features['companies']);
        $this->assertTrue($features['forms']);
        $this->assertTrue($features['custom_fields']);
        $this->assertFalse($features['quotes']);
        $this->assertFalse($features['api_access']);
    }

    public function test_for_user_uses_the_effective_plan(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['sales_reports' => true],
        ]));

        $this->assertTrue(FeatureGate::forUser($user->fresh())->enabled('sales_reports'));
    }

    public function test_for_user_without_a_plan_disables_every_gate(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->assertFalse(FeatureGate::forUser($user)->enabled('sales_reports'));
    }

    public function test_label_comes_from_config(): void
    {
        $this->assertSame('Tasks & reminders', FeatureGate::forPlan(null)->label('tasks'));
        $this->assertSame('teleportation', FeatureGate::forPlan(null)->label('teleportation'));
    }
}
