<?php

namespace Tests\Feature\Automation;

use App\Events\LeadAssigned;
use App\Events\LeadDealValueChanged;
use App\Events\LeadScoreChanged;
use App\Models\Workspace;
use App\Modules\Automation\Jobs\ScanWorkspaceTimeTriggersJob;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\AutomationTriggerDispatch;
use App\Modules\Automation\Models\WorkspaceBusinessHours;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Automation\Services\TimeTriggerScanner;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadPipeline;
use App\Modules\Leads\Models\LeadPipelineStage;
use App\Modules\Leads\Models\LeadTask;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\CustomField;
use App\Modules\Shared\Models\CustomFieldValue;
use App\Services\FeatureGate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 3.4 (§7.4) automation enhancements: business hours, the five new
 * event/scheduled triggers, and the three CRM actions.
 *
 * These are base automation features — none are gated on sales_reports.
 */
class AutomationEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private $user;

    protected function setUp(): void
    {
        parent::setUp();

        $ctx = $this->createWorkspaceContext();
        $this->workspace = $ctx['workspace'];
        $this->user = $ctx['user'];
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array{0: Lead, 1: Contact} */
    private function leadWithContact(array $attrs = []): array
    {
        $lead = Lead::factory()->create($attrs + [
            'workspace_id' => $this->workspace->id,
            'pipeline_id' => null,
            'stage_id' => null,
        ]);

        $contact = Contact::factory()->create([
            'workspace_id' => $this->workspace->id,
            'lead_id' => $lead->id,
        ]);

        return [$lead, $contact];
    }

    private function makeAutomation(string $triggerType, array $config = [], ?int $workspaceId = null): Automation
    {
        return Automation::create([
            'workspace_id' => $workspaceId ?? $this->workspace->id,
            'name' => 'Enhancement flow',
            'status' => 'active',
            'trigger_type' => $triggerType,
            'trigger_config' => $config,
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'add_tag', 'position' => ['x' => 0, 'y' => 100], 'data' => ['nodeType' => 'add_tag', 'tag' => 'touched']],
            ],
            'edges' => [['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1']],
        ]);
    }

    private function runSingleAction(string $nodeType, array $data, ?int $contactId = null, array $context = []): AutomationRun
    {
        $automation = Automation::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Action flow',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'trigger_config' => [],
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => $nodeType, 'position' => ['x' => 0, 'y' => 100], 'data' => ['nodeType' => $nodeType] + $data],
            ],
            'edges' => [['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1']],
        ]);

        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $contactId,
            'status' => 'pending',
            'context' => $context,
            'started_at' => now(),
        ]);

        app(AutomationEngine::class)->executeRun($run);

        return $run->fresh();
    }

    private function scanner(): TimeTriggerScanner
    {
        return app(TimeTriggerScanner::class);
    }

    // ─── Business hours ───────────────────────────────────────────────────────

    #[Test]
    public function business_hours_default_to_a_weekday_nine_to_five_week(): void
    {
        $hours = WorkspaceBusinessHours::forWorkspace($this->workspace->id);

        $this->assertSame('UTC', $hours->timezone);
        $this->assertTrue($hours->isOpenAt(Carbon::parse('2026-11-02 12:00:00'))); // Monday
        $this->assertTrue($hours->isOpenAt(Carbon::parse('2026-11-02 09:00:00')));
        $this->assertFalse($hours->isOpenAt(Carbon::parse('2026-11-02 08:59:00')));
        $this->assertFalse($hours->isOpenAt(Carbon::parse('2026-11-02 17:00:00')));
        $this->assertFalse($hours->isOpenAt(Carbon::parse('2026-11-07 12:00:00'))); // Saturday
    }

    #[Test]
    public function business_hours_are_evaluated_in_the_workspace_timezone(): void
    {
        $hours = WorkspaceBusinessHours::forWorkspace($this->workspace->id);
        $hours->update([
            'timezone' => 'Asia/Riyadh', // UTC+3, no daylight saving
            'schedule' => [
                'mon' => ['start' => '09:00', 'end' => '17:00'],
                'tue' => null, 'wed' => null, 'thu' => null, 'fri' => null, 'sat' => null, 'sun' => null,
            ],
        ]);
        $hours->refresh();

        // 07:00 UTC is 10:00 in Riyadh.
        $this->assertTrue($hours->isOpenAt(Carbon::parse('2026-11-02 07:00:00', 'UTC')));
        // 16:00 UTC is 19:00 in Riyadh — after close.
        $this->assertFalse($hours->isOpenAt(Carbon::parse('2026-11-02 16:00:00', 'UTC')));
    }

    #[Test]
    public function business_hours_can_be_saved_through_the_settings_endpoint(): void
    {
        $this->actingAs($this->user)
            ->put(route('client.automations.business-hours.update'), [
                'timezone' => 'Europe/London',
                'schedule' => [
                    'mon' => ['start' => '08:30', 'end' => '16:30'],
                    'tue' => null,
                ],
            ])
            ->assertSessionHasNoErrors();

        $hours = WorkspaceBusinessHours::where('workspace_id', $this->workspace->id)->first();
        $this->assertNotNull($hours);
        $this->assertSame('Europe/London', $hours->timezone);
        $this->assertEquals(['start' => '08:30', 'end' => '16:30'], $hours->schedule['mon']);
        $this->assertNull($hours->schedule['tue']);
        $this->assertNull($hours->schedule['wed'], 'Unsubmitted days default to closed.');
    }

    #[Test]
    public function business_hours_saving_rejects_an_invalid_timezone_or_window(): void
    {
        $this->actingAs($this->user)
            ->put(route('client.automations.business-hours.update'), [
                'timezone' => 'Mars/Olympus_Mons',
                'schedule' => ['mon' => ['start' => '25:00', 'end' => 'later']],
            ])
            ->assertSessionHasErrors(['timezone', 'schedule.mon.start', 'schedule.mon.end']);

        $this->assertDatabaseMissing('workspace_business_hours', [
            'workspace_id' => $this->workspace->id,
        ]);
    }

    #[Test]
    public function business_hours_are_scoped_to_the_saving_workspace(): void
    {
        $other = Workspace::factory()->create();
        $this->provisionWorkspace($other);

        $this->actingAs($this->user)
            ->put(route('client.automations.business-hours.update'), [
                'timezone' => 'Europe/Berlin',
                'schedule' => ['mon' => ['start' => '09:00', 'end' => '17:00']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('Europe/Berlin', WorkspaceBusinessHours::where('workspace_id', $this->workspace->id)->value('timezone'));
        $this->assertDatabaseMissing('workspace_business_hours', ['workspace_id' => $other->id]);
    }

    /** Give a freshly created workspace an owner + client so model relations resolve. */
    private function provisionWorkspace(Workspace $workspace): void
    {
        $workspace->update(['owner_id' => $this->user->id]);
    }

    // ─── lead.assigned ────────────────────────────────────────────────────────

    #[Test]
    public function lead_assigned_fires_when_the_lead_has_a_contact(): void
    {
        Queue::fake();
        Notification::fake();
        $automation = $this->makeAutomation('lead.assigned');
        [$lead, $contact] = $this->leadWithContact();

        LeadAssigned::dispatch($lead, $this->user);

        $run = AutomationRun::where('automation_id', $automation->id)->first();
        $this->assertNotNull($run);
        $this->assertSame($contact->id, $run->contact_id);
        $this->assertSame((string) $this->user->id, $run->context['assigned_user_id']);
    }

    #[Test]
    public function lead_assigned_is_skipped_for_a_lead_without_a_contact(): void
    {
        Queue::fake();
        Notification::fake();
        $automation = $this->makeAutomation('lead.assigned');
        $lead = Lead::factory()->create(['workspace_id' => $this->workspace->id]);

        LeadAssigned::dispatch($lead, $this->user);

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());
    }

    // ─── lead.score_threshold ─────────────────────────────────────────────────

    #[Test]
    public function score_threshold_fires_only_on_an_upward_crossing(): void
    {
        Queue::fake();
        $automation = $this->makeAutomation('lead.score_threshold', ['threshold' => 80]);
        [$lead, $contact] = $this->leadWithContact();

        LeadScoreChanged::dispatch($this->workspace->id, $lead->id, $contact->id, 70, 85, 'hot');

        $run = AutomationRun::where('automation_id', $automation->id)->first();
        $this->assertNotNull($run, 'A score crossing 80 upward should start the flow.');
        $this->assertSame('85', $run->context['lead_score']);
        $this->assertSame('70', $run->context['lead_previous_score']);

        $run->update(['status' => 'completed', 'completed_at' => now()]);

        // Already above the threshold: this is not a new crossing.
        LeadScoreChanged::dispatch($this->workspace->id, $lead->id, $contact->id, 85, 92, 'hot');

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    #[Test]
    public function score_threshold_ignores_a_downward_move(): void
    {
        Queue::fake();
        $automation = $this->makeAutomation('lead.score_threshold', ['threshold' => 80]);
        [$lead, $contact] = $this->leadWithContact();

        LeadScoreChanged::dispatch($this->workspace->id, $lead->id, $contact->id, 90, 60, 'warm');

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());
    }

    #[Test]
    public function score_threshold_requires_a_configured_threshold(): void
    {
        Queue::fake();
        $automation = $this->makeAutomation('lead.score_threshold');
        [$lead, $contact] = $this->leadWithContact();

        LeadScoreChanged::dispatch($this->workspace->id, $lead->id, $contact->id, 10, 95, 'hot');

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());
    }

    // ─── task.overdue ─────────────────────────────────────────────────────────

    #[Test]
    public function overdue_scan_fires_once_per_task(): void
    {
        Queue::fake();
        $automation = $this->makeAutomation('task.overdue');
        [$lead] = $this->leadWithContact();

        $task = LeadTask::create([
            'workspace_id' => $this->workspace->id,
            'lead_id' => $lead->id,
            'title' => 'Call back',
            'type' => 'call',
            'priority' => 'high',
            'due_at' => now()->subDay(),
        ]);

        $counts = $this->scanner()->scanWorkspace($this->workspace->id);
        $this->assertSame(1, $counts['task.overdue']);

        // A second scan must not duplicate the dispatch.
        $this->scanner()->scanWorkspace($this->workspace->id);

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
        $this->assertSame(1, AutomationTriggerDispatch::where([
            'automation_id' => $automation->id,
            'trigger_type' => 'task.overdue',
            'subject_type' => 'lead_task',
            'subject_id' => $task->id,
        ])->count());
    }

    #[Test]
    public function overdue_scan_ignores_tasks_whose_lead_has_no_contact(): void
    {
        Queue::fake();
        $automation = $this->makeAutomation('task.overdue');
        $lead = Lead::factory()->create(['workspace_id' => $this->workspace->id]);

        LeadTask::create([
            'workspace_id' => $this->workspace->id,
            'lead_id' => $lead->id,
            'title' => 'Orphan task',
            'due_at' => now()->subDay(),
        ]);

        $this->scanner()->scanWorkspace($this->workspace->id);

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());
    }

    // ─── lead.inactive ────────────────────────────────────────────────────────

    #[Test]
    public function inactive_scan_fires_after_the_configured_window_and_dedupes(): void
    {
        Queue::fake();
        $automation = $this->makeAutomation('lead.inactive', ['days' => 14]);
        [$lead, $contact] = $this->leadWithContact(['last_activity_at' => now()->subDays(20)]);

        $counts = $this->scanner()->scanWorkspace($this->workspace->id);
        $this->assertSame(1, $counts['lead.inactive']);

        $run = AutomationRun::where('automation_id', $automation->id)->first();
        $this->assertNotNull($run);
        $this->assertSame($contact->id, $run->contact_id);
        $this->assertSame('14', $run->context['inactive_days']);

        $this->scanner()->scanWorkspace($this->workspace->id);
        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    #[Test]
    public function inactive_scan_rearms_after_new_activity(): void
    {
        Queue::fake();
        $automation = $this->makeAutomation('lead.inactive', ['days' => 14]);
        [$lead] = $this->leadWithContact(['last_activity_at' => now()->subDays(20)]);

        $this->scanner()->scanWorkspace($this->workspace->id);
        $first = AutomationRun::where('automation_id', $automation->id)->firstOrFail();
        $first->update(['status' => 'completed', 'completed_at' => now()]);

        // Fresh activity moves the reference date forward and re-arms the window.
        $lead->update(['last_activity_at' => now()]);
        Carbon::setTestNow(now()->addDays(20));

        $this->scanner()->scanWorkspace($this->workspace->id);

        $this->assertSame(2, AutomationRun::where('automation_id', $automation->id)->count());
    }

    #[Test]
    public function inactive_scan_skips_won_and_lost_leads(): void
    {
        Queue::fake();
        $automation = $this->makeAutomation('lead.inactive', ['days' => 14]);
        $this->leadWithContact(['last_activity_at' => now()->subDays(30), 'won_at' => now()->subDay()]);

        $this->scanner()->scanWorkspace($this->workspace->id);

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());
    }

    // ─── lead.date_reached ────────────────────────────────────────────────────

    #[Test]
    public function date_reached_scan_fires_when_the_custom_field_is_today(): void
    {
        Queue::fake();
        [$lead, $contact] = $this->leadWithContact();
        $field = $this->dateField();
        $this->setFieldValue($field, $lead, now()->toDateString());

        $automation = $this->makeAutomation('lead.date_reached', ['field_id' => $field->id]);

        $counts = $this->scanner()->scanWorkspace($this->workspace->id);
        $this->assertSame(1, $counts['lead.date_reached']);

        $run = AutomationRun::where('automation_id', $automation->id)->first();
        $this->assertNotNull($run);
        $this->assertSame($contact->id, $run->contact_id);
        $this->assertSame(now()->toDateString(), $run->context['date_reached']);

        // Same day, same lead: no second dispatch.
        $this->scanner()->scanWorkspace($this->workspace->id);
        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    #[Test]
    public function date_reached_scan_ignores_other_dates_and_fields(): void
    {
        Queue::fake();
        [$lead] = $this->leadWithContact();
        $field = $this->dateField();
        $this->setFieldValue($field, $lead, now()->addDays(3)->toDateString());

        $automation = $this->makeAutomation('lead.date_reached', ['field_id' => $field->id]);

        $this->scanner()->scanWorkspace($this->workspace->id);

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());
    }

    #[Test]
    public function date_reached_requires_a_configured_field(): void
    {
        Queue::fake();
        $automation = $this->makeAutomation('lead.date_reached');
        [$lead] = $this->leadWithContact();
        $field = $this->dateField();
        $this->setFieldValue($field, $lead, now()->toDateString());

        $this->scanner()->scanWorkspace($this->workspace->id);

        $this->assertSame(0, AutomationRun::where('automation_id', $automation->id)->count());
    }

    private function dateField(): CustomField
    {
        return CustomField::create([
            'workspace_id' => $this->workspace->id,
            'entity_type' => 'lead',
            'key' => 'renewal_date',
            'label' => 'Renewal date',
            'type' => 'date',
            'sort_order' => 0,
        ]);
    }

    private function setFieldValue(CustomField $field, Lead $lead, string $value): void
    {
        CustomFieldValue::create([
            'workspace_id' => $this->workspace->id,
            'field_id' => $field->id,
            'entity_type' => 'lead',
            'entity_id' => $lead->id,
            'value' => $value,
        ]);
    }

    // ─── Actions ──────────────────────────────────────────────────────────────

    #[Test]
    public function create_task_action_creates_a_task_for_the_linked_lead(): void
    {
        [$lead, $contact] = $this->leadWithContact();

        $run = $this->runSingleAction('create_task', [
            'title' => 'Follow up with {{contact.first_name}}',
            'description' => 'Check the renewal',
            'type' => 'call',
            'priority' => 'high',
            'due_amount' => 2,
            'due_unit' => 'days',
        ], $contact->id);

        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertSame('ok', $run->logs()->first()->result);

        $task = LeadTask::where('lead_id', $lead->id)->first();
        $this->assertNotNull($task);
        $this->assertSame('Follow up with '.$contact->first_name, $task->title);
        $this->assertSame('Check the renewal', $task->description);
        $this->assertSame('call', $task->type);
        $this->assertSame('high', $task->priority);
        $this->assertTrue($task->due_at->between(now()->addDays(2)->subMinute(), now()->addDays(2)->addMinute()));
    }

    #[Test]
    public function move_to_pipeline_action_moves_the_lead_to_the_stage(): void
    {
        [$lead, $contact] = $this->leadWithContact();

        $pipeline = LeadPipeline::create([
            'workspace_id' => $this->workspace->id,
            'name' => 'Renewals',
            'is_default' => false,
            'sort_order' => 1,
        ]);
        $stage = LeadPipelineStage::create([
            'workspace_id' => $this->workspace->id,
            'pipeline_id' => $pipeline->id,
            'name' => 'Negotiation',
            'color' => 'amber',
            'position' => 0,
            'is_won' => false,
            'is_lost' => false,
            'default_probability' => 50,
        ]);

        $run = $this->runSingleAction('move_to_pipeline', ['stage_id' => $stage->id], $contact->id);

        $this->assertSame('completed', $run->status, $run->error ?? '');

        $lead->refresh();
        $this->assertSame($stage->id, $lead->stage_id);
        $this->assertSame($pipeline->id, $lead->pipeline_id);
    }

    #[Test]
    public function update_deal_value_action_sets_and_increments_the_value(): void
    {
        Event::fake([LeadDealValueChanged::class]);
        [$lead, $contact] = $this->leadWithContact(['deal_value' => 1000, 'currency' => 'USD']);

        $run = $this->runSingleAction('update_deal_value', ['mode' => 'increase', 'amount' => 500], $contact->id);

        $this->assertSame('completed', $run->status, $run->error ?? '');
        $this->assertEquals(1500.0, (float) $lead->fresh()->deal_value);

        Event::assertDispatched(LeadDealValueChanged::class, function (LeadDealValueChanged $event) use ($lead) {
            return $event->leadId === $lead->id
                && $event->fromValue === 1000.0
                && $event->toValue === 1500.0
                && $event->currency === 'USD';
        });
    }

    #[Test]
    public function update_deal_value_action_never_goes_below_zero(): void
    {
        [$lead, $contact] = $this->leadWithContact(['deal_value' => 100]);

        $this->runSingleAction('update_deal_value', ['mode' => 'decrease', 'amount' => 500], $contact->id);

        $this->assertEquals(0.0, (float) $lead->fresh()->deal_value);
    }

    #[Test]
    public function crm_actions_skip_when_the_contact_is_not_linked_to_a_lead(): void
    {
        $contact = Contact::factory()->create([
            'workspace_id' => $this->workspace->id,
            'lead_id' => null,
        ]);

        $run = $this->runSingleAction('create_task', ['title' => 'Nothing to do'], $contact->id);

        $this->assertSame('skipped', $run->logs()->first()->result);
        $this->assertSame(0, LeadTask::where('workspace_id', $this->workspace->id)->count());
    }

    // ─── Gate-free + isolation ────────────────────────────────────────────────

    #[Test]
    public function scheduled_triggers_run_without_the_sales_reports_feature(): void
    {
        $this->assertFalse(
            FeatureGate::forWorkspace($this->workspace)->enabled('sales_reports'),
            'This test must run against a workspace without sales_reports.'
        );

        Queue::fake();
        $automation = $this->makeAutomation('task.overdue');
        [$lead] = $this->leadWithContact();

        LeadTask::create([
            'workspace_id' => $this->workspace->id,
            'lead_id' => $lead->id,
            'title' => 'Gate-free follow-up',
            'due_at' => now()->subHour(),
        ]);

        $this->scanner()->scanWorkspace($this->workspace->id);

        $this->assertSame(1, AutomationRun::where('automation_id', $automation->id)->count());
    }

    #[Test]
    public function the_scan_only_touches_its_own_workspace(): void
    {
        Queue::fake();

        $other = Workspace::factory()->create();
        $otherAutomation = $this->makeAutomation('task.overdue', [], $other->id);
        $otherLead = Lead::factory()->create(['workspace_id' => $other->id]);
        Contact::factory()->create(['workspace_id' => $other->id, 'lead_id' => $otherLead->id]);
        LeadTask::create([
            'workspace_id' => $other->id,
            'lead_id' => $otherLead->id,
            'title' => 'Their overdue task',
            'due_at' => now()->subDay(),
        ]);

        $this->scanner()->scanWorkspace($this->workspace->id);

        $this->assertSame(0, AutomationRun::where('automation_id', $otherAutomation->id)->count());
    }

    #[Test]
    public function the_scan_command_queues_one_job_per_workspace_with_a_time_trigger(): void
    {
        Queue::fake();
        $this->makeAutomation('lead.inactive', ['days' => 7]);

        $this->artisan('automation:scan-time-triggers')
            ->expectsOutputToContain('1 workspace')
            ->assertSuccessful();

        Queue::assertPushedOn('automation', ScanWorkspaceTimeTriggersJob::class);
        Queue::assertPushed(ScanWorkspaceTimeTriggersJob::class, fn ($job) => $job->workspaceId === $this->workspace->id);
    }
}
