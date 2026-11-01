<?php

namespace Tests\Feature\AI;

use App\Models\Plan;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadScoreEvent;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Leads\Services\Risk\AtRiskDetector;
use App\Modules\Leads\Services\Risk\LeadRisk;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 6 at-risk detection: the evidence a lead is going cold, computed from
 * data the app already has rather than from a model call.
 */
class AtRiskDetectionTest extends TestCase
{
    use RefreshDatabase;

    private function detector(): AtRiskDetector
    {
        return app(AtRiskDetector::class);
    }

    private function lead(int $workspaceId, array $attrs = []): Lead
    {
        return Lead::factory()->create($attrs + [
            'workspace_id' => $workspaceId,
            'won_at' => null,
            'lost_at' => null,
            'next_follow_up_at' => null,
            'stage_changed_at' => null,
            'expected_close_date' => null,
            'score_band' => 'warm',
        ]);
    }

    #[Test]
    public function a_lead_that_has_been_quiet_for_two_weeks_is_at_risk(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $lead = $this->lead($workspace->id, [
            'created_at' => now()->subDays(20),
            'last_activity_at' => now()->subDays(16),
        ]);

        $risk = $this->detector()->analyzeOne($lead);

        $this->assertSame(LeadRisk::LEVEL_MEDIUM, $risk->level);
        $this->assertSame('no_activity', $risk->reasons[0]['code']);
        $this->assertSame(16, $risk->reasons[0]['days']);
        $this->assertSame(35, $risk->score);
    }

    #[Test]
    public function a_brand_new_lead_is_not_at_risk(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $lead = $this->lead($workspace->id);

        $risk = $this->detector()->analyzeOne($lead);

        $this->assertFalse($risk->isAtRisk());
        $this->assertSame(LeadRisk::LEVEL_NONE, $risk->level);
        $this->assertSame([], $risk->reasons);
        $this->assertNull($risk->headline());
    }

    #[Test]
    public function an_overdue_follow_up_adds_weight_on_top_of_silence(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $lead = $this->lead($workspace->id, [
            'created_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(30),
            'next_follow_up_at' => now()->subDays(3),
        ]);

        $risk = $this->detector()->analyzeOne($lead);

        $this->assertSame(LeadRisk::LEVEL_HIGH, $risk->level);
        $this->assertSame('no_activity', $risk->reasons[0]['code']);

        $overdue = collect($risk->reasons)->firstWhere('code', 'overdue_follow_up');
        $this->assertNotNull($overdue);
        $this->assertSame(3, $overdue['days']);
    }

    #[Test]
    public function a_follow_up_due_today_is_not_yet_a_risk_signal(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $lead = $this->lead($workspace->id, [
            'last_activity_at' => now(),
            'next_follow_up_at' => now()->startOfDay(),
        ]);

        $risk = $this->detector()->analyzeOne($lead);

        $this->assertFalse($risk->isAtRisk());
    }

    #[Test]
    public function a_recorded_decay_event_appears_as_evidence(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $lead = $this->lead($workspace->id, ['last_activity_at' => now()->subDays(31)]);

        LeadScoreEvent::create([
            'lead_id' => $lead->id,
            'event' => 'inactivity_30d',
            'points' => -15,
            'reason' => 'No engagement for 30 days',
            'created_at' => now()->subDay(),
        ]);

        $risk = $this->detector()->analyzeOne($lead);

        $decay = collect($risk->reasons)->firstWhere('code', 'score_decay');
        $this->assertNotNull($decay);
        $this->assertStringContainsString('No engagement for 30 days', $decay['detail']);
    }

    #[Test]
    public function a_lead_stuck_in_one_stage_is_flagged(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $lead = $this->lead($workspace->id, [
            'last_activity_at' => now(),
            'stage_changed_at' => now()->subDays(25),
        ]);

        $risk = $this->detector()->analyzeOne($lead);

        $stalled = collect($risk->reasons)->firstWhere('code', 'stalled_stage');
        $this->assertNotNull($stalled);
        $this->assertSame(25, $stalled['days']);
        $this->assertSame(LeadRisk::LEVEL_LOW, $risk->level);
    }

    #[Test]
    public function a_missed_close_date_counts_while_the_deal_is_open(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $lead = $this->lead($workspace->id, [
            'last_activity_at' => now(),
            'expected_close_date' => now()->subDays(9)->startOfDay(),
        ]);

        $risk = $this->detector()->analyzeOne($lead);

        $past = collect($risk->reasons)->firstWhere('code', 'past_close_date');
        $this->assertNotNull($past);
        $this->assertSame(9, $past['days']);
    }

    #[Test]
    public function a_settled_deal_is_never_at_risk(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $won = $this->lead($workspace->id, [
            'last_activity_at' => now()->subDays(90),
            'won_at' => now()->subDay(),
            'next_follow_up_at' => now()->subDays(10),
        ]);

        $this->assertFalse($this->detector()->analyzeOne($won)->isAtRisk());
    }

    #[Test]
    public function a_lead_in_a_won_or_lost_stage_is_never_at_risk(): void
    {
        ['workspace' => $workspace] = $this->createWorkspaceContext();

        $stage = LeadPipelineStage::create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => app(PipelineManager::class)->defaultPipelineFor($workspace->id)->id,
            'name' => 'Closed won',
            'color' => LeadPipelineStage::COLORS[0],
            'position' => 0,
            'is_won' => true,
        ]);

        $lead = $this->lead($workspace->id, [
            'stage_id' => $stage->id,
            'last_activity_at' => now()->subDays(60),
        ]);

        $this->assertFalse($this->detector()->analyzeOne($lead)->isAtRisk());
    }

    #[Test]
    public function the_board_carries_risk_only_for_plans_with_ai_sales(): void
    {
        Carbon::setTestNow(now());

        ['user' => $withAi, 'workspace' => $aiWorkspace, 'client' => $aiClient] = $this->createWorkspaceContext();
        $this->attachPlanToClient($aiClient, Plan::factory()->create([
            'slug' => 'ai-on',
            'feature_flags' => ['ai_sales' => true],
        ]));

        $stage = app(PipelineManager::class)->stagesFor($aiWorkspace->id)->first();
        $this->lead($aiWorkspace->id, [
            'stage_id' => $stage->id,
            'created_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(30),
        ]);

        $response = $this->actingAs($withAi)->get(route('client.leads.pipeline.index'))->assertOk();

        $risk = data_get($response->viewData('page')['props'], 'stages.0.leads.0.risk');

        $this->assertIsArray($risk);
        $this->assertSame('high', $risk['level']);
        $this->assertNotEmpty($risk['reasons']);

        Carbon::setTestNow();

        ['user' => $withoutAi, 'workspace' => $plainWorkspace, 'client' => $plainClient] = $this->createWorkspaceContext();
        $this->attachPlanToClient($plainClient, Plan::factory()->create([
            'slug' => 'ai-off',
            'feature_flags' => ['ai_sales' => false],
        ]));

        $plainStage = app(PipelineManager::class)->stagesFor($plainWorkspace->id)->first();
        $this->lead($plainWorkspace->id, [
            'stage_id' => $plainStage->id,
            'created_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(30),
        ]);

        $plain = $this->actingAs($withoutAi)->get(route('client.leads.pipeline.index'))->assertOk();

        $plainLead = data_get($plain->viewData('page')['props'], 'stages.0.leads.0');

        $this->assertIsArray($plainLead);
        $this->assertArrayNotHasKey('risk', $plainLead);
    }

    #[Test]
    public function the_lead_detail_modal_carries_the_reasons(): void
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create([
            'slug' => 'ai-on-detail',
            'feature_flags' => ['ai_sales' => true],
        ]));

        $stage = app(PipelineManager::class)->stagesFor($workspace->id)->first();

        $lead = $this->lead($workspace->id, [
            'stage_id' => $stage->id,
            'created_at' => now()->subDays(40),
            'last_activity_at' => now()->subDays(30),
        ]);

        $response = $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['lead' => $lead->id]))
            ->assertOk();

        $risk = data_get($response->viewData('page')['props'], 'leadDetail.risk');

        $this->assertSame('high', $risk['level']);
        $this->assertSame('no_activity', $risk['reasons'][0]['code']);
        $this->assertNotEmpty($risk['headline']);
    }
}
