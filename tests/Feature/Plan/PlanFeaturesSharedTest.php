<?php

namespace Tests\Feature\Plan;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PlanFeaturesSharedTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_effective_plan_and_its_features_are_shared_with_inertia(): void
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'name' => 'Pro',
            'slug' => 'pro',
            'feature_flags' => [
                'tasks' => true,
                'companies' => true,
                'quotes' => false,
                'sales_reports' => true,
            ],
        ]));

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('plan.name', 'Pro')
                ->where('plan.slug', 'pro')
                ->where('plan_features.tasks', true)
                ->where('plan_features.companies', true)
                ->where('plan_features.quotes', false)
                ->where('plan_features.sales_reports', true)
            );
    }

    public function test_a_user_without_a_plan_receives_null_plan_and_all_gates_disabled(): void
    {
        ['user' => $user] = $this->createWorkspaceContext();

        $this->actingAs($user)
            ->get(route('client.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('plan', null)
                ->where('plan_features.tasks', false)
                ->where('plan_features.api_access', false)
            );
    }
}
