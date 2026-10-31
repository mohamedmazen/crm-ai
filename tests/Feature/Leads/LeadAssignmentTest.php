<?php

namespace Tests\Feature\Leads;

use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Models\LeadAssignmentConfig;
use App\Modules\Leads\Services\LeadAssignmentService;
use App\Modules\Leads\Services\PipelineManager;
use App\Notifications\LeadAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        return [$user, $workspace, $client];
    }

    #[Test]
    public function manual_lead_assignment_updates_lead_and_records_activity(): void
    {
        [$user, $workspace, $client] = $this->actor();

        // Create a team member under same client
        $member = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'status' => User::STATUS_ACTIVE,
        ]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => null,
        ]);

        $response = $this->actingAs($user)
            ->put(route('client.leads.pipeline.assign', $lead->id), [
                'user_id' => $member->id,
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $lead->refresh();
        $this->assertEquals($member->id, $lead->assigned_user_id);
        $this->assertNotNull($lead->assigned_at);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'user_id' => $user->id,
            'type' => 'assigned',
        ]);
    }

    #[Test]
    public function lead_can_be_unassigned(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => $user->id,
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->put(route('client.leads.pipeline.assign', $lead->id), [
                'user_id' => null,
            ]);

        $response->assertRedirect();

        $lead->refresh();
        $this->assertNull($lead->assigned_user_id);

        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'assigned')->latest()->first();
        $this->assertNotNull($activity);
        $this->assertEquals('Unassigned', $activity->body);
    }

    #[Test]
    public function cannot_assign_lead_to_user_from_different_organization(): void
    {
        [$user, $workspace] = $this->actor();
        ['user' => $otherUser] = $this->createWorkspaceContext();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $response = $this->actingAs($user)
            ->put(route('client.leads.pipeline.assign', $lead->id), [
                'user_id' => $otherUser->id,
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function board_can_be_filtered_by_my_leads(): void
    {
        [$user, $workspace, $client] = $this->actor();

        $otherMember = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'status' => User::STATUS_ACTIVE,
        ]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $myLead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => $user->id,
            'name' => 'My Lead',
        ]);

        $otherLead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => $otherMember->id,
            'name' => 'Other Lead',
        ]);

        $response = $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['assigned' => 'me']))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Leads/Pipeline')
            ->where('boardTotal', 1)
        );
    }

    #[Test]
    public function board_can_be_filtered_by_unassigned(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => $user->id,
        ]);

        Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => null,
        ]);

        $response = $this->actingAs($user)
            ->get(route('client.leads.pipeline.index', ['assigned' => 'unassigned']))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Leads/Pipeline')
            ->where('boardTotal', 1)
        );
    }

    #[Test]
    public function round_robin_auto_assignment_rotates_between_eligible_members(): void
    {
        [$user1, $workspace, $client] = $this->actor();

        $user2 = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'status' => User::STATUS_ACTIVE,
            'name' => 'Beta User',
        ]);
        $user1->update(['name' => 'Alpha User']);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        LeadAssignmentConfig::create([
            'pipeline_id' => $pipeline->id,
            'mode' => LeadAssignmentConfig::MODE_ROUND_ROBIN,
        ]);

        $assignmentService = app(LeadAssignmentService::class);

        $lead1 = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);
        $lead2 = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);
        $lead3 = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $assigned1 = $assignmentService->autoAssign($lead1);
        $assigned2 = $assignmentService->autoAssign($lead2);
        $assigned3 = $assignmentService->autoAssign($lead3);

        $this->assertEquals($user1->id, $assigned1->id);
        $this->assertEquals($user2->id, $assigned2->id);
        $this->assertEquals($user1->id, $assigned3->id);
    }

    #[Test]
    public function load_balanced_auto_assignment_picks_user_with_fewest_open_leads(): void
    {
        [$user1, $workspace, $client] = $this->actor();

        $user2 = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'status' => User::STATUS_ACTIVE,
            'name' => 'Free Agent',
        ]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        // Give user1 2 active leads
        Lead::factory()->count(2)->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => $user1->id,
            'status' => 'new',
        ]);

        LeadAssignmentConfig::create([
            'pipeline_id' => $pipeline->id,
            'mode' => LeadAssignmentConfig::MODE_LOAD_BALANCED,
        ]);

        $newLead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $assigned = app(LeadAssignmentService::class)->autoAssign($newLead);

        $this->assertEquals($user2->id, $assigned->id);
    }

    #[Test]
    public function pipeline_settings_can_update_assignment_mode(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);

        $response = $this->actingAs($user)
            ->put(route('client.leads.pipelines.update', $pipeline->id), [
                'assignment_mode' => 'round_robin',
            ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('lead_assignment_configs', [
            'pipeline_id' => $pipeline->id,
            'mode' => 'round_robin',
        ]);
    }

    #[Test]
    public function assigning_a_lead_notifies_the_new_assignee(): void
    {
        [$user, $workspace, $client] = $this->actor();

        $member = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'status' => User::STATUS_ACTIVE,
        ]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => null,
        ]);

        Notification::fake();

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.assign', $lead->id), ['user_id' => $member->id])
            ->assertRedirect();

        Notification::assertSentTo(
            $member,
            LeadAssignedNotification::class,
            fn (LeadAssignedNotification $notification) => $notification->lead->id === $lead->id
                && $notification->assignedBy?->id === $user->id,
        );

        Notification::assertNotSentTo($user, LeadAssignedNotification::class);
    }

    #[Test]
    public function assigning_a_lead_to_yourself_does_not_notify(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => null,
        ]);

        Notification::fake();

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.assign', $lead->id), ['user_id' => $user->id])
            ->assertRedirect();

        Notification::assertNothingSent();
    }

    #[Test]
    public function reassigning_the_same_user_does_not_notify_twice(): void
    {
        [$user, $workspace, $client] = $this->actor();

        $member = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'status' => User::STATUS_ACTIVE,
        ]);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'assigned_user_id' => null,
        ]);

        Notification::fake();

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.assign', $lead->id), ['user_id' => $member->id])
            ->assertRedirect();

        $this->actingAs($user)
            ->put(route('client.leads.pipeline.assign', $lead->id), ['user_id' => $member->id])
            ->assertRedirect();

        Notification::assertSentToTimes($member, LeadAssignedNotification::class, 1);
    }

    #[Test]
    public function auto_assignment_notifies_the_rotated_user(): void
    {
        [$user1, $workspace, $client] = $this->actor();

        $user2 = User::factory()->create([
            'client_id' => $client->id,
            'workspace_id' => $workspace->id,
            'status' => User::STATUS_ACTIVE,
            'name' => 'Beta User',
        ]);
        $user1->update(['name' => 'Alpha User']);

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        LeadAssignmentConfig::create([
            'pipeline_id' => $pipeline->id,
            'mode' => LeadAssignmentConfig::MODE_ROUND_ROBIN,
        ]);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        Notification::fake();

        $assigned = app(LeadAssignmentService::class)->autoAssign($lead);

        Notification::assertSentTo(
            $assigned,
            LeadAssignedNotification::class,
            fn (LeadAssignedNotification $notification) => $notification->lead->id === $lead->id
                && $notification->assignedBy === null,
        );

        // Sanity: the rotation picked the alphabetically-first eligible user.
        $this->assertSame($user1->id, $assigned->id);
        $this->assertNotSame($user2->id, $assigned->id);
    }
}
