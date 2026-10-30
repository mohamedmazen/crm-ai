<?php

namespace Tests\Feature\Leads;

use App\Models\User;
use App\Modules\Leads\Jobs\SendTaskReminderJob;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadTask;
use App\Modules\Leads\Services\PipelineManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LeadTaskTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        return [$user, $workspace, $client];
    }

    #[Test]
    public function task_can_be_created_for_a_lead(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $dueAt = now()->addDays(2)->startOfMinute();

        $response = $this->actingAs($user)
            ->post(route('client.leads.tasks.store', $lead->id), [
                'title' => 'Follow up on proposal',
                'description' => 'Discuss enterprise plan pricing details',
                'type' => 'call',
                'priority' => 'high',
                'due_at' => $dueAt->toDateTimeString(),
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('lead_tasks', [
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'title' => 'Follow up on proposal',
            'type' => 'call',
            'priority' => 'high',
        ]);

        $this->assertDatabaseHas('lead_activities', [
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'type' => 'task_created',
        ]);
    }

    #[Test]
    public function creating_or_updating_task_synchronizes_lead_next_follow_up_at(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'next_follow_up_at' => null,
        ]);

        $farDue = now()->addDays(5)->startOfMinute();
        $nearDue = now()->addDays(2)->startOfMinute();

        // Create far task
        $taskFar = LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'title' => 'Far task',
            'type' => 'todo',
            'priority' => 'medium',
            'due_at' => $farDue,
        ]);

        $lead->refresh();
        $this->assertEquals($farDue->toDateTimeString(), $lead->next_follow_up_at?->toDateTimeString());

        // Create nearer task -> should become next_follow_up_at
        $taskNear = LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'title' => 'Near task',
            'type' => 'call',
            'priority' => 'high',
            'due_at' => $nearDue,
        ]);

        $lead->refresh();
        $this->assertEquals($nearDue->toDateTimeString(), $lead->next_follow_up_at?->toDateTimeString());
    }

    #[Test]
    public function toggling_task_completed_updates_completed_at_and_resyncs_next_follow_up(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $nearDue = now()->addDays(2)->startOfMinute();
        $farDue = now()->addDays(5)->startOfMinute();

        $taskNear = LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'title' => 'Near task',
            'due_at' => $nearDue,
        ]);

        $taskFar = LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'title' => 'Far task',
            'due_at' => $farDue,
        ]);

        // Complete the near task via toggle
        $response = $this->actingAs($user)
            ->patch(route('client.leads.tasks.toggle', $taskNear->id));

        $response->assertRedirect();

        $taskNear->refresh();
        $this->assertNotNull($taskNear->completed_at);
        $this->assertTrue($taskNear->isCompleted());

        // Lead next_follow_up_at should now shift to the far task
        $lead->refresh();
        $this->assertEquals($farDue->toDateTimeString(), $lead->next_follow_up_at?->toDateTimeString());

        // Complete the far task
        $this->actingAs($user)
            ->patch(route('client.leads.tasks.toggle', $taskFar->id));

        // Now lead has no open tasks with due date
        $lead->refresh();
        $this->assertNull($lead->next_follow_up_at);
    }

    #[Test]
    public function deleting_task_resyncs_lead_next_follow_up_at(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $task = LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'title' => 'Only task',
            'due_at' => now()->addDay(),
        ]);

        $lead->refresh();
        $this->assertNotNull($lead->next_follow_up_at);

        $response = $this->actingAs($user)
            ->delete(route('client.leads.tasks.destroy', $task->id));

        $response->assertRedirect();
        $this->assertDatabaseMissing('lead_tasks', ['id' => $task->id]);

        $lead->refresh();
        $this->assertNull($lead->next_follow_up_at);
    }

    #[Test]
    public function cannot_create_task_for_lead_in_another_workspace(): void
    {
        [$user] = $this->actor();
        ['workspace' => $otherWorkspace] = $this->createWorkspaceContext();

        $otherLead = Lead::factory()->create([
            'workspace_id' => $otherWorkspace->id,
        ]);

        $response = $this->actingAs($user)
            ->post(route('client.leads.tasks.store', $otherLead->id), [
                'title' => 'Sneaky task',
                'type' => 'todo',
                'priority' => 'low',
            ]);

        $response->assertForbidden();
    }

    #[Test]
    public function task_dashboard_displays_tasks_with_correct_counts_and_filters(): void
    {
        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        // 1 overdue task assigned to user
        LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'assigned_user_id' => $user->id,
            'title' => 'Overdue Task',
            'due_at' => now()->subDay(),
        ]);

        // 1 today task assigned to user
        LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'assigned_user_id' => $user->id,
            'title' => 'Today Task',
            'due_at' => now()->addHour(),
        ]);

        // 1 upcoming task assigned to user
        LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'assigned_user_id' => $user->id,
            'title' => 'Upcoming Task',
            'due_at' => now()->addDays(3),
        ]);

        $response = $this->actingAs($user)
            ->get(route('client.tasks.index', ['status' => 'today', 'assigned' => 'me']))
            ->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Tasks/Index')
            ->where('counts.today', 1)
            ->where('counts.overdue', 1)
            ->where('counts.upcoming', 1)
            ->has('tasks.data', 1)
            ->where('tasks.data.0.title', 'Today Task')
        );
    }

    #[Test]
    public function process_task_reminders_command_dispatches_reminder_jobs(): void
    {
        Queue::fake();

        [$user, $workspace] = $this->actor();

        $pipeline = app(PipelineManager::class)->defaultPipelineFor($workspace->id);
        $stage = app(PipelineManager::class)->firstStage($workspace->id, $pipeline->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        $task = LeadTask::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'assigned_user_id' => $user->id,
            'title' => 'Urgent Call',
            'due_at' => now()->addMinutes(10),
            'reminded_at' => null,
        ]);

        $this->artisan('leads:process-task-reminders')
            ->expectsOutputToContain('Dispatched 1 task reminder job(s).')
            ->assertSuccessful();

        Queue::assertPushed(SendTaskReminderJob::class, function ($job) use ($task) {
            return $job->task->id === $task->id;
        });
    }
}

