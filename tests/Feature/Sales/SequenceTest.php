<?php

namespace Tests\Feature\Sales;

use App\Events\LeadStageChanged;
use App\Events\MessageReceived;
use App\Events\SequenceEnrolled;
use App\Events\SequenceReplied;
use App\Events\SequenceStepSent;
use App\Listeners\StopSequencesOnReply;
use App\Listeners\StopSequencesOnWon;
use App\Models\Plan;
use App\Modules\Automation\Models\Automation;
use App\Modules\Automation\Models\AutomationRun;
use App\Modules\Automation\Models\WorkspaceBusinessHours;
use App\Modules\Automation\Services\AutomationEngine;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Jobs\SendSequenceStepJob;
use App\Modules\Sales\Models\Sequence;
use App\Modules\Sales\Models\SequenceEnrollment;
use App\Modules\Sales\Models\SequenceStep;
use App\Modules\Sales\Services\SequenceEngine;
use App\Modules\Sales\Services\SequenceMessageSender;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SequenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{0: mixed, 1: mixed, 2: mixed} */
    private function actorWithPlan(mixed $sequencesFeature = 10): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => ['sequences' => $sequencesFeature],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
    }

    private function makeSequence($workspace, array $overrides = []): Sequence
    {
        return Sequence::create(array_merge([
            'workspace_id' => $workspace->id,
            'name' => 'Nurture sequence',
            'status' => Sequence::STATUS_ACTIVE,
        ], $overrides));
    }

    private function addStep(Sequence $sequence, int $position, array $overrides = []): SequenceStep
    {
        return $sequence->steps()->create(array_merge([
            'position' => $position,
            'channel' => Sequence::CHANNEL_EMAIL,
            'delay_hours' => 0,
            'subject' => 'Hello',
            'body' => 'Hi {{contact.name}}',
        ], $overrides));
    }

    private function fakeSender(): SequenceMessageSender
    {
        $fake = new class extends SequenceMessageSender
        {
            /** @var array<int, int> */
            public array $sent = [];

            public function send(Sequence $sequence, SequenceStep $step, SequenceEnrollment $enrollment): ?string
            {
                $this->sent[] = $step->position;

                return 'test-message-'.$step->position;
            }
        };

        $this->app->instance(SequenceMessageSender::class, $fake);

        return $fake;
    }

    #[Test]
    public function sequences_feature_gate_blocks_when_disabled(): void
    {
        [$user] = $this->actorWithPlan(sequencesFeature: 0);

        $this->actingAs($user)
            ->get(route('client.sales.sequences.index'))
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('upgrade_required', true);
    }

    #[Test]
    public function can_create_sequences_and_the_plan_limit_is_enforced(): void
    {
        [$user, $workspace] = $this->actorWithPlan(sequencesFeature: 2);

        $this->actingAs($user)
            ->post(route('client.sales.sequences.store'), ['name' => 'First sequence'])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('client.sales.sequences.store'), ['name' => 'Second sequence'])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('client.sales.sequences.store'), ['name' => 'Third sequence'])
            ->assertSessionHasErrors('name');

        $this->assertSame(2, Sequence::where('workspace_id', $workspace->id)->count());
    }

    #[Test]
    public function activating_a_sequence_requires_at_least_one_step(): void
    {
        [$user, $workspace] = $this->actorWithPlan();
        $sequence = $this->makeSequence($workspace, ['status' => Sequence::STATUS_DRAFT]);

        $this->actingAs($user)
            ->post(route('client.sales.sequences.status', $sequence->id), ['status' => Sequence::STATUS_ACTIVE])
            ->assertSessionHasErrors('status');

        $this->assertSame(Sequence::STATUS_DRAFT, $sequence->fresh()->status);
    }

    #[Test]
    public function builder_saves_ordered_steps_and_removes_the_rest(): void
    {
        [$user, $workspace] = $this->actorWithPlan();
        $sequence = $this->makeSequence($workspace, ['status' => Sequence::STATUS_DRAFT]);

        $keep = $this->addStep($sequence, 1, ['body' => 'Old body']);
        $removed = $this->addStep($sequence, 2);

        $this->actingAs($user)
            ->put(route('client.sales.sequences.builder.update', $sequence->id), [
                'name' => 'Renamed sequence',
                'status' => Sequence::STATUS_ACTIVE,
                'stop_on_reply' => true,
                'stop_on_won' => true,
                'respect_hours' => false,
                'steps' => [
                    ['id' => $keep->id, 'channel' => 'email', 'delay_hours' => 0, 'subject' => 'Step one', 'body' => 'Body one'],
                    ['channel' => 'whatsapp', 'delay_hours' => 48, 'subject' => 'ignored', 'body' => 'Body two'],
                ],
            ])
            ->assertRedirect();

        $sequence->refresh();

        $this->assertSame('Renamed sequence', $sequence->name);
        $this->assertSame(Sequence::STATUS_ACTIVE, $sequence->status);
        $this->assertFalse($sequence->respect_hours);
        $this->assertSame([1, 2], $sequence->steps()->pluck('position')->all());
        $this->assertSame(['email', 'whatsapp'], $sequence->steps()->pluck('channel')->all());
        $this->assertNull($sequence->steps()->where('position', 2)->value('subject'));
        $this->assertDatabaseMissing('sequence_steps', ['id' => $removed->id]);
    }

    #[Test]
    public function enrolling_a_lead_schedules_the_first_step_and_emits_the_event(): void
    {
        [, $workspace] = $this->actorWithPlan();
        Event::fake([SequenceEnrolled::class]);

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $sequence = $this->makeSequence($workspace);
        $this->addStep($sequence, 1, ['delay_hours' => 2]);

        $enrollment = app(SequenceEngine::class)->enroll($sequence, $lead);

        $this->assertSame(SequenceEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertSame(0, $enrollment->current_step);
        $this->assertTrue($enrollment->next_run_at->between(now()->addHour(), now()->addHours(3)));

        Event::assertDispatched(SequenceEnrolled::class, fn (SequenceEnrolled $event) => $event->leadId === $lead->id
            && $event->sequenceId === $sequence->id);
    }

    #[Test]
    public function a_due_step_is_sent_and_the_enrollment_advances(): void
    {
        [, $workspace] = $this->actorWithPlan();
        $fake = $this->fakeSender();
        Event::fake([SequenceStepSent::class]);

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $sequence = $this->makeSequence($workspace, ['respect_hours' => false]);
        $this->addStep($sequence, 1, ['delay_hours' => 0]);
        $this->addStep($sequence, 2, ['delay_hours' => 5]);

        $engine = app(SequenceEngine::class);
        $enrollment = $engine->enroll($sequence, $lead);

        $engine->sendNextStep($enrollment->id);

        $enrollment->refresh();

        $this->assertSame([1], $fake->sent);
        $this->assertSame(1, $enrollment->current_step);
        $this->assertSame(SequenceEnrollment::STATUS_ACTIVE, $enrollment->status);
        $this->assertTrue($enrollment->next_run_at->between(now()->addHours(4), now()->addHours(6)));

        $this->assertDatabaseHas('sequence_events', [
            'enrollment_id' => $enrollment->id,
            'step_position' => 1,
            'type' => 'sent',
        ]);

        Event::assertDispatched(SequenceStepSent::class, fn (SequenceStepSent $event) => $event->position === 1
            && $event->messageId === 'test-message-1');
    }

    #[Test]
    public function the_last_step_completes_the_enrollment(): void
    {
        [, $workspace] = $this->actorWithPlan();
        $fake = $this->fakeSender();

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $sequence = $this->makeSequence($workspace, ['respect_hours' => false]);
        $this->addStep($sequence, 1);

        $engine = app(SequenceEngine::class);
        $enrollment = $engine->enroll($sequence, $lead);

        $engine->sendNextStep($enrollment->id);

        $this->assertSame([1], $fake->sent);
        $this->assertSame(SequenceEnrollment::STATUS_COMPLETED, $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->next_run_at);
    }

    #[Test]
    public function the_command_queues_due_enrollments_once(): void
    {
        [, $workspace] = $this->actorWithPlan();
        Queue::fake();

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $sequence = $this->makeSequence($workspace);
        $this->addStep($sequence, 1);

        $enrollment = app(SequenceEngine::class)->enroll($sequence, $lead);
        $enrollment->update(['next_run_at' => now()->subMinute()]);

        $this->artisan('sequences:process')->assertSuccessful();
        $this->artisan('sequences:process')->assertSuccessful();

        Queue::assertPushed(SendSequenceStepJob::class, 1);
    }

    #[Test]
    public function respect_business_hours_postpones_a_closed_send(): void
    {
        [, $workspace] = $this->actorWithPlan();
        $fake = $this->fakeSender();

        WorkspaceBusinessHours::updateOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'timezone' => 'UTC',
                'schedule' => array_fill_keys(WorkspaceBusinessHours::DAYS, null),
            ],
        );

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $sequence = $this->makeSequence($workspace, ['respect_hours' => true]);
        $this->addStep($sequence, 1);

        $engine = app(SequenceEngine::class);
        $enrollment = $engine->enroll($sequence, $lead);

        $engine->sendNextStep($enrollment->id);

        $this->assertSame([], $fake->sent);
        $this->assertTrue($enrollment->fresh()->next_run_at->gt(now()->addHours(23)));
    }

    #[Test]
    public function a_reply_stops_active_enrollments_and_emits_the_reply_event(): void
    {
        [, $workspace] = $this->actorWithPlan();
        Event::fake([SequenceReplied::class]);

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'lead_id' => $lead->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'Yes, tell me more',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $sequence = $this->makeSequence($workspace, ['stop_on_reply' => true]);
        $this->addStep($sequence, 1);

        $enrollment = app(SequenceEngine::class)->enroll($sequence, $lead);

        app(StopSequencesOnReply::class)->handle(new MessageReceived($message));

        $enrollment->refresh();

        $this->assertSame(SequenceEnrollment::STATUS_STOPPED, $enrollment->status);
        $this->assertSame('replied', $enrollment->stop_reason);
        $this->assertDatabaseHas('sequence_events', [
            'enrollment_id' => $enrollment->id,
            'type' => 'reply',
        ]);

        Event::assertDispatched(SequenceReplied::class, fn (SequenceReplied $event) => $event->leadId === $lead->id
            && $event->enrollmentId === $enrollment->id);
    }

    #[Test]
    public function a_reply_is_ignored_when_stop_on_reply_is_off(): void
    {
        [, $workspace] = $this->actorWithPlan();

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'lead_id' => $lead->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);
        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'in',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'reply',
            'status' => 'delivered',
            'sent_at' => now(),
        ]);

        $sequence = $this->makeSequence($workspace, ['stop_on_reply' => false]);
        $this->addStep($sequence, 1);

        $enrollment = app(SequenceEngine::class)->enroll($sequence, $lead);

        app(StopSequencesOnReply::class)->handle(new MessageReceived($message));

        $this->assertSame(SequenceEnrollment::STATUS_ACTIVE, $enrollment->fresh()->status);
    }

    #[Test]
    public function a_won_stage_stops_active_enrollments(): void
    {
        [, $workspace] = $this->actorWithPlan();

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $sequence = $this->makeSequence($workspace, ['stop_on_won' => true]);
        $this->addStep($sequence, 1);

        $enrollment = app(SequenceEngine::class)->enroll($sequence, $lead);

        app(StopSequencesOnWon::class)->handle(new LeadStageChanged(
            workspaceId: (int) $workspace->id,
            leadId: (int) $lead->id,
            contactId: null,
            fromStage: 'New',
            toStage: 'Won',
            isWon: true,
        ));

        $enrollment->refresh();

        $this->assertSame(SequenceEnrollment::STATUS_STOPPED, $enrollment->status);
        $this->assertSame('won', $enrollment->stop_reason);
    }

    #[Test]
    public function the_signed_unsubscribe_url_stops_the_enrollment(): void
    {
        [, $workspace] = $this->actorWithPlan();

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $sequence = $this->makeSequence($workspace);
        $this->addStep($sequence, 1);

        $enrollment = app(SequenceEngine::class)->enroll($sequence, $lead);

        $url = URL::signedRoute('sequences.unsubscribe', ['enrollment' => $enrollment->id]);

        $this->get($url)->assertOk();

        $enrollment->refresh();

        $this->assertSame(SequenceEnrollment::STATUS_STOPPED, $enrollment->status);
        $this->assertSame('unsubscribed', $enrollment->stop_reason);
        $this->assertDatabaseHas('sequence_events', [
            'enrollment_id' => $enrollment->id,
            'type' => 'unsubscribe',
        ]);
    }

    #[Test]
    public function the_automation_action_enrolls_the_run_lead_in_a_sequence(): void
    {
        [, $workspace] = $this->actorWithPlan();

        $lead = Lead::factory()->create(['workspace_id' => $workspace->id]);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'lead_id' => $lead->id]);

        $sequence = $this->makeSequence($workspace);
        $this->addStep($sequence, 1);

        $automation = Automation::create([
            'workspace_id' => $workspace->id,
            'name' => 'Enroll flow',
            'status' => 'active',
            'trigger_type' => 'contact.created',
            'trigger_config' => [],
            'nodes' => [
                ['id' => 'trigger-1', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
                ['id' => 'n1', 'type' => 'enroll_in_sequence', 'position' => ['x' => 0, 'y' => 100], 'data' => [
                    'nodeType' => 'enroll_in_sequence',
                    'sequence_id' => $sequence->id,
                ]],
            ],
            'edges' => [['id' => 'e1', 'source' => 'trigger-1', 'target' => 'n1']],
        ]);

        $run = AutomationRun::create([
            'automation_id' => $automation->id,
            'contact_id' => $contact->id,
            'status' => 'pending',
            'context' => ['lead_id' => $lead->id],
            'started_at' => now(),
        ]);

        app(AutomationEngine::class)->executeRun($run);

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertDatabaseHas('sequence_enrollments', [
            'sequence_id' => $sequence->id,
            'lead_id' => $lead->id,
            'status' => SequenceEnrollment::STATUS_ACTIVE,
        ]);
    }
}
