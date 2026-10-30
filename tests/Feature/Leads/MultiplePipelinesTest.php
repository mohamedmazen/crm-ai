<?php

namespace Tests\Feature\Leads;

use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadCloseReason;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadStageHistory;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MultiplePipelinesTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        return [$user, $workspace, $client];
    }

    #[Test]
    public function board_loads_default_pipeline_and_calculates_deal_economics(): void
    {
        [$user, $workspace] = $this->actor();

        $manager = app(PipelineManager::class);
        $stages = $manager->stagesFor($workspace->id);
        $defaultPipeline = $manager->defaultPipelineFor($workspace->id);

        $qualifiedStage = $stages->firstWhere('name', 'Qualified');

        // Create a lead with deal economics
        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $defaultPipeline->id,
            'stage_id' => $qualifiedStage->id,
            'deal_value' => 5000.00,
            'probability' => 60,
        ]);

        $response = $this->actingAs($user)
            ->get(route('client.leads.pipeline.index'))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Leads/Pipeline')
            ->has('pipelines', 1)
            ->where('activePipelineId', $defaultPipeline->id)
            ->where('boardDealValue', 5000)
            ->where('boardWeightedValue', 3000) // 5000 * 60 / 100
        );
    }

    #[Test]
    public function user_with_multi_pipeline_feature_can_create_new_pipeline(): void
    {
        [$user, $workspace, $client] = $this->actor();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['multi_pipeline' => true],
        ]));

        $response = $this->actingAs($user)
            ->post(route('client.leads.pipelines.store'), [
                'name' => 'Enterprise Sales',
                'is_default' => false,
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('lead_pipelines', [
            'workspace_id' => $workspace->id,
            'name' => 'Enterprise Sales',
            'is_default' => 0,
        ]);

        $newPipeline = LeadPipeline::where('workspace_id', $workspace->id)
            ->where('name', 'Enterprise Sales')
            ->first();

        // New pipeline stages must be seeded
        $this->assertSame(
            count(LeadPipelineStage::DEFAULTS),
            $newPipeline->stages()->count()
        );
    }

    #[Test]
    public function user_without_multi_pipeline_feature_is_blocked_by_feature_gate(): void
    {
        [$user, $workspace, $client] = $this->actor();

        $this->attachPlanToClient($client, Plan::factory()->create([
            'feature_flags' => ['multi_pipeline' => false],
        ]));

        $response = $this->actingAs($user)
            ->post(route('client.leads.pipelines.store'), [
                'name' => 'Enterprise Sales',
            ]);

        $response->assertRedirect(route('client.pricing'));
        $response->assertSessionHas('upgrade_required', true);

        $this->assertDatabaseMissing('lead_pipelines', [
            'workspace_id' => $workspace->id,
            'name' => 'Enterprise Sales',
        ]);
    }

    #[Test]
    public function moving_lead_records_stage_history(): void
    {
        [$user, $workspace] = $this->actor();
        $manager = app(PipelineManager::class);
        $stages = $manager->stagesFor($workspace->id);

        $firstStage = $stages->firstWhere('name', 'New');
        $targetStage = $stages->firstWhere('name', 'Contacted');

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'stage_id' => $firstStage->id,
            'board_position' => 0,
        ]);

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.move', $lead), [
                'stage_id' => $targetStage->id,
                'position' => 0,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('lead_stage_history', [
            'lead_id' => $lead->id,
            'from_stage_id' => $firstStage->id,
            'to_stage_id' => $targetStage->id,
            'user_id' => $user->id,
        ]);
    }

    #[Test]
    public function moving_lead_to_won_stage_updates_status_won_at_and_probability(): void
    {
        [$user, $workspace] = $this->actor();
        $manager = app(PipelineManager::class);
        $stages = $manager->stagesFor($workspace->id);

        $newStage = $stages->firstWhere('name', 'New');
        $wonStage = $stages->firstWhere('is_won', true);

        $reason = LeadCloseReason::create([
            'workspace_id' => $workspace->id,
            'type' => 'won',
            'name' => 'Great value',
            'sort_order' => 0,
        ]);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'stage_id' => $newStage->id,
            'deal_value' => 2000,
        ]);

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.move', $lead), [
                'stage_id' => $wonStage->id,
                'position' => 0,
                'close_reason_id' => $reason->id,
            ])
            ->assertRedirect();

        $lead->refresh();
        $this->assertSame('converted', $lead->status);
        $this->assertSame(100, $lead->probability);
        $this->assertNotNull($lead->won_at);
        $this->assertNull($lead->lost_at);
        $this->assertSame($reason->id, $lead->close_reason_id);
    }

    #[Test]
    public function moving_lead_to_lost_stage_updates_status_lost_at_and_close_reason(): void
    {
        [$user, $workspace] = $this->actor();
        $manager = app(PipelineManager::class);
        $stages = $manager->stagesFor($workspace->id);

        $newStage = $stages->firstWhere('name', 'New');
        $lostStage = $stages->firstWhere('is_lost', true);

        $reason = LeadCloseReason::create([
            'workspace_id' => $workspace->id,
            'type' => 'lost',
            'name' => 'Competitor discount',
            'sort_order' => 0,
        ]);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'stage_id' => $newStage->id,
            'deal_value' => 2000,
        ]);

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.move', $lead), [
                'stage_id' => $lostStage->id,
                'position' => 0,
                'close_reason_id' => $reason->id,
            ])
            ->assertRedirect();

        $lead->refresh();
        $this->assertSame('lost', $lead->status);
        $this->assertSame(0, $lead->probability);
        $this->assertNotNull($lead->lost_at);
        $this->assertNull($lead->won_at);
        $this->assertSame($reason->id, $lead->close_reason_id);
    }

    #[Test]
    public function updating_lead_deal_fields_persists_economics(): void
    {
        [$user, $workspace] = $this->actor();

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
        ]);

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.deal.update', $lead), [
                'deal_value' => 15000.50,
                'currency' => 'USD',
                'probability' => 75,
                'expected_close_date' => '2026-12-31',
                'status' => 'qualified',
            ])
            ->assertRedirect();

        $lead->refresh();
        $this->assertEquals(15000.50, (float) $lead->deal_value);
        $this->assertSame('USD', $lead->currency);
        $this->assertSame(75, $lead->probability);
        $this->assertSame('2026-12-31', $lead->expected_close_date->toDateString());
        $this->assertSame('qualified', $lead->status);
    }
}

