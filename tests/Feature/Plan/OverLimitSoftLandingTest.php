<?php

namespace Tests\Feature\Plan;

use App\Models\Plan;
use App\Modules\AI\Models\AiChatbot;
use App\Modules\Shared\Models\Contact;
use App\Services\PlanUsage;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Soft landing for workspaces whose data is larger than the plan they choose:
 * nothing is deleted, existing data keeps working, and only new creations are
 * blocked once a counted resource is over its cap.
 */
class OverLimitSoftLandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_trial_is_feature_complete_but_capped_at_business_data_limits(): void
    {
        $this->seed(PlanSeeder::class);

        $trial = Plan::where('slug', 'trial')->firstOrFail();
        $business = Plan::where('slug', 'business')->firstOrFail();

        foreach (array_keys(config('features.labels')) as $feature) {
            $this->assertTrue($trial->hasFeature($feature), "Trial should unlock {$feature}.");
            $this->assertNull($trial->featureLimit($feature), "Trial should leave {$feature} uncapped.");
        }

        foreach (['users', 'storage', 'max_contacts', 'knowledge_bases', 'chatbots'] as $key) {
            $this->assertSame(
                $business->limitValue($key),
                $trial->limitValue($key),
                "Trial {$key} must match Business.",
            );
        }
    }

    public function test_plan_usage_reports_the_overage_for_a_workspace_above_its_contact_cap(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create(['limits' => ['max_contacts' => 2]]);
        $this->attachPlanToClient($client, $plan);

        Contact::factory()->count(5)->create(['workspace_id' => $workspace->id]);

        $workspace = $workspace->fresh('client');
        $usage = app(PlanUsage::class)->summary($workspace, $plan);

        $this->assertTrue($usage['max_contacts']['is_over']);
        $this->assertSame(5, $usage['max_contacts']['current']);
        $this->assertSame(2, $usage['max_contacts']['limit']);
        $this->assertSame(3, $usage['max_contacts']['over_by']);
        $this->assertSame(100, $usage['max_contacts']['percent']);

        $breaches = app(PlanUsage::class)->breaches($workspace, $plan);

        $this->assertCount(1, $breaches);
        $this->assertSame('max_contacts', $breaches[0]['key']);
        $this->assertSame(3, $breaches[0]['over_by']);
    }

    public function test_creating_an_over_limit_resource_explains_the_overage_and_keeps_existing_rows(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create(['limits' => ['chatbots' => 1]]);
        $this->attachPlanToClient($client, $plan);

        AiChatbot::create(['workspace_id' => $workspace->id, 'name' => 'Existing A', 'enabled' => true]);
        AiChatbot::create(['workspace_id' => $workspace->id, 'name' => 'Existing B', 'enabled' => true]);

        $response = $this->actingAs($user)
            ->post(route('client.ai.chatbots.store'), ['name' => 'New bot']);

        $response->assertRedirect('/billing');
        $response->assertSessionHas('upgrade_required', true);
        $response->assertSessionHas(
            'upgrade_reason',
            fn (string $reason) => str_contains($reason, 'over its chatbots limit (2/1, 1 over)'),
        );

        // Soft landing: the existing bots were neither deleted nor altered.
        $this->assertSame(2, AiChatbot::where('workspace_id', $workspace->id)->count());
    }

    public function test_the_shared_usage_prop_marks_an_over_limit_resource(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create(['limits' => ['max_contacts' => 1]]);
        $this->attachPlanToClient($client, $plan);

        Contact::factory()->count(3)->create(['workspace_id' => $workspace->id]);

        $response = $this->actingAs($user)->get(route('client.dashboard'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('current_workspace_usage.max_contacts.current', 3)
            ->where('current_workspace_usage.max_contacts.limit', 1)
            ->where('current_workspace_usage.max_contacts.is_over', true)
            ->where('current_workspace_usage.max_contacts.over_by', 2));
    }

    public function test_the_pricing_page_flags_only_the_plans_the_workspace_data_outgrows(): void
    {
        $this->seed(PlanSeeder::class);

        ['user' => $user, 'workspace' => $workspace] = $this->createWorkspaceContext();

        // 5,001 contacts is over Starter (5,000) but under Pro (50,000).
        Contact::factory()->count(5001)->create(['workspace_id' => $workspace->id]);

        $response = $this->actingAs($user)->get(route('client.pricing'));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('plans', function ($plans) {
                $plans = collect($plans);
                $starter = $plans->firstWhere('slug', 'starter');
                $pro = $plans->firstWhere('slug', 'pro');

                return $starter['data_fits'] === false
                    && $starter['blocking_limits'][0]['key'] === 'max_contacts'
                    && $pro['data_fits'] === true;
            }));
    }
}
