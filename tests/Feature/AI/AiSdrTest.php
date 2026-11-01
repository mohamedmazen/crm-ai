<?php

namespace Tests\Feature\AI;

use App\Models\Plan;
use App\Modules\AI\Services\Llm\LlmResponse;
use App\Modules\AI\Services\LlmGateway;
use App\Modules\Automation\Models\WorkspaceBusinessHours;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Sales\Jobs\RunAiSdrJob;
use App\Modules\Sales\Models\AiSdrSettings;
use App\Modules\Sales\Models\Sequence;
use App\Modules\Sales\Models\SequenceEnrollment;
use App\Modules\Sales\Models\SequenceStep;
use App\Modules\Sales\Services\Ai\AiSdrEngine;
use App\Modules\Sales\Services\SequenceMessageSender;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The AI SDR: an autonomous sender that must never outrun its guard rails.
 *
 * The model and the delivery path are faked for most cases — what is under
 * test is the product contract: who may be written to, when, how often, and
 * that a human reply is the stop signal.
 */
class AiSdrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{user: mixed, workspace: mixed, client: mixed} */
    private function actor(array $features = ['ai_sdr' => true]): array
    {
        ['user' => $user, 'workspace' => $workspace, 'client' => $client] = $this->createWorkspaceContext();

        $this->attachPlanToClient($client, Plan::factory()->create(['feature_flags' => $features]));

        return ['user' => $user, 'workspace' => $workspace, 'client' => $client];
    }

    /** Every day counts as open, independent of the clock the suite runs at. */
    private function openAllWeek(int $workspaceId): void
    {
        WorkspaceBusinessHours::forWorkspace($workspaceId)->update([
            'timezone' => 'UTC',
            'schedule' => array_fill_keys(WorkspaceBusinessHours::DAYS, ['start' => '00:00', 'end' => '00:00']),
        ]);
    }

    private function closeAllWeek(int $workspaceId): void
    {
        WorkspaceBusinessHours::forWorkspace($workspaceId)->update([
            'timezone' => 'UTC',
            'schedule' => array_fill_keys(WorkspaceBusinessHours::DAYS, null),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function settings(int $workspaceId, array $overrides = []): AiSdrSettings
    {
        $settings = AiSdrSettings::forWorkspace($workspaceId);

        $settings->update(array_merge([
            'enabled' => true,
            'channel' => AiSdrSettings::CHANNEL_EMAIL,
            'daily_cap' => 20,
            'per_lead_cap' => 3,
            'min_hours_between' => 48,
        ], $overrides));

        return $settings->refresh();
    }

    /**
     * A lead that was pushed to contacts and has one outbound message. When
     * $replied is true an inbound message newer than the outbound one is added.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function contactedLead(int $workspaceId, bool $replied = false, array $overrides = []): Lead
    {
        $lead = Lead::factory()->create(array_merge([
            'workspace_id' => $workspaceId,
            'name' => 'Amina Rahman',
            'pushed_to_contacts' => true,
            'status' => 'contacted',
        ], $overrides));

        $contact = Contact::factory()->create([
            'workspace_id' => $workspaceId,
            'lead_id' => $lead->id,
            'first_name' => 'Amina',
            'email' => 'amina'.$lead->id.'@example.com',
            'phone_e164' => '+88017000'.$lead->id,
            'opt_in_email' => true,
            'opt_in_whatsapp' => true,
        ]);

        $conversation = Conversation::create([
            'workspace_id' => $workspaceId,
            'contact_id' => $contact->id,
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        Message::create([
            'conversation_id' => $conversation->id,
            'direction' => 'out',
            'channel' => 'email',
            'type' => 'text',
            'body' => 'Would 500 units suit your launch?',
            'status' => 'sent',
            'sent_by' => 'human',
            'sent_at' => now()->subHour(),
        ]);

        if ($replied) {
            Message::create([
                'conversation_id' => $conversation->id,
                'direction' => 'in',
                'channel' => 'email',
                'type' => 'text',
                'body' => 'Not right now, thanks.',
                'status' => 'delivered',
                'sent_by' => 'human',
                'sent_at' => now(),
            ]);
        }

        return $lead->refresh();
    }

    /** A delivery double that records what the engine asked it to send. */
    private function fakeSender(): SequenceMessageSender
    {
        $fake = new class extends SequenceMessageSender
        {
            /** @var array<int, array{sequence: Sequence, step: SequenceStep, enrollment: SequenceEnrollment}> */
            public array $sent = [];

            public function send(Sequence $sequence, SequenceStep $step, SequenceEnrollment $enrollment): ?string
            {
                $this->sent[] = compact('sequence', 'step', 'enrollment');

                return 'test-message';
            }
        };

        $this->app->instance(SequenceMessageSender::class, $fake);

        return $fake;
    }

    private function aiReply(string $subject = 'Your 500-unit quote', string $body = 'Hi Amina, following up on the 500 units we discussed.'): string
    {
        return json_encode([
            'subject' => $subject,
            'body' => $body,
            'reason' => 'They asked about pricing and never replied.',
        ]);
    }

    #[Test]
    public function it_sends_a_follow_up_to_a_contacted_lead_that_has_not_replied(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $lead = $this->contactedLead($workspace->id);
        $this->fakeLlmGateway($this->aiReply(body: "Hi Amina,\n\nFollowing up on the 500 units we discussed."));
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(['sent' => 1, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(1, $sender->sent);

        $sent = $sender->sent[0];

        $this->assertSame($workspace->id, (int) $sent['sequence']->workspace_id);
        $this->assertSame(Sequence::SOURCE_AI_SDR, $sent['sequence']->source);
        $this->assertSame($lead->id, (int) $sent['enrollment']->lead_id);
        $this->assertSame('email', $sent['step']->channel);
        $this->assertSame('Your 500-unit quote', $sent['step']->subject);
        $this->assertStringContainsString('500 units', $sent['step']->body);
        // Newlines become line breaks so the email reads as written.
        $this->assertStringContainsString('<br', $sent['step']->body);
    }

    #[Test]
    public function the_send_is_recorded_on_the_timeline_with_its_reason(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $lead = $this->contactedLead($workspace->id);
        $this->fakeLlmGateway($this->aiReply());
        $this->fakeSender();

        app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $activity = LeadActivity::where('lead_id', $lead->id)->where('type', 'email')->firstOrFail();

        $this->assertSame('ai_sdr', $activity->meta['source']);
        $this->assertSame('Your 500-unit quote', $activity->meta['subject']);
        $this->assertSame('They asked about pricing and never replied.', $activity->meta['reason']);
        $this->assertStringContainsString('500 units', $activity->body);
        $this->assertNotNull($lead->fresh()->last_activity_at);
    }

    #[Test]
    public function the_prompt_carries_the_lead_and_its_conversation(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $this->contactedLead($workspace->id);

        $captured = null;

        $mock = Mockery::mock(LlmGateway::class);
        $mock->shouldReceive('chat')->andReturnUsing(function (
            int $workspaceId,
            array $messages,
            array $opts = [],
            ?int $chatbotId = null,
            ?int $conversationId = null,
            ?string $feature = null,
        ) use (&$captured) {
            $captured = ['messages' => $messages, 'opts' => $opts, 'feature' => $feature];

            return new LlmResponse($this->aiReply(), 100, 50, 'test-model', 12);
        });

        $this->instance(LlmGateway::class, $mock);
        $this->fakeSender();

        app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $prompt = $captured['messages'][0]['content'];

        $this->assertSame('ai_sdr', $captured['feature']);
        $this->assertArrayHasKey('system', $captured['opts']);
        $this->assertStringContainsString('Amina Rahman', $prompt);
        $this->assertStringContainsString('rep: Would 500 units suit your launch?', $prompt);
    }

    #[Test]
    public function a_plan_without_ai_sdr_sends_nothing(): void
    {
        ['workspace' => $workspace] = $this->actor(['ai_sdr' => false]);
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $this->contactedLead($workspace->id);

        // No expectation set: any AI call fails the test.
        $this->instance(LlmGateway::class, Mockery::mock(LlmGateway::class));
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(0, $sender->sent);
        $this->assertSame(0, LeadActivity::where('type', 'email')->count());
    }

    #[Test]
    public function the_kill_switch_stops_everything(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id, ['enabled' => false]);

        $this->contactedLead($workspace->id);
        $this->instance(LlmGateway::class, Mockery::mock(LlmGateway::class));
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(0, $sender->sent);
    }

    #[Test]
    public function nothing_is_sent_outside_business_hours(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->closeAllWeek($workspace->id);
        $this->settings($workspace->id);

        $this->contactedLead($workspace->id);
        $this->instance(LlmGateway::class, Mockery::mock(LlmGateway::class));
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(0, $sender->sent);
    }

    #[Test]
    public function a_lead_that_replied_is_left_alone(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $this->contactedLead($workspace->id, replied: true);
        $this->instance(LlmGateway::class, Mockery::mock(LlmGateway::class));
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(0, $sender->sent);
    }

    #[Test]
    public function a_lead_that_was_never_contacted_is_skipped(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $lead = Lead::factory()->create([
            'workspace_id' => $workspace->id,
            'pushed_to_contacts' => true,
            'status' => 'new',
        ]);

        Contact::factory()->create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'email' => 'amina@example.com',
        ]);

        $this->instance(LlmGateway::class, Mockery::mock(LlmGateway::class));
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(0, $sender->sent);
    }

    #[Test]
    public function a_won_or_lost_lead_is_skipped(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $this->contactedLead($workspace->id, overrides: ['status' => 'converted']);
        $this->contactedLead($workspace->id, overrides: ['status' => 'lost']);

        $this->instance(LlmGateway::class, Mockery::mock(LlmGateway::class));
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(0, $sender->sent);
    }

    #[Test]
    public function the_daily_cap_stops_the_run(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id, ['daily_cap' => 1, 'per_lead_cap' => 5, 'min_hours_between' => 1]);

        $lead = $this->contactedLead($workspace->id);

        LeadActivity::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'type' => 'email',
            'body' => 'An earlier nudge.',
            'meta' => ['source' => 'ai_sdr'],
            'occurred_at' => now(),
        ]);

        $this->instance(LlmGateway::class, Mockery::mock(LlmGateway::class));
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(0, $sender->sent);
    }

    #[Test]
    public function the_per_lead_cap_stops_further_nudges(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id, ['per_lead_cap' => 1, 'min_hours_between' => 1]);

        $lead = $this->contactedLead($workspace->id);

        LeadActivity::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'type' => 'email',
            'body' => 'An earlier nudge.',
            'meta' => ['source' => 'ai_sdr'],
            'occurred_at' => now()->subHours(2),
        ]);

        $this->instance(LlmGateway::class, Mockery::mock(LlmGateway::class));
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(0, $sender->sent);
    }

    #[Test]
    public function the_cooling_off_window_stops_a_second_nudge_too_soon(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id, ['min_hours_between' => 48, 'per_lead_cap' => 5]);

        $lead = $this->contactedLead($workspace->id);

        LeadActivity::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'type' => 'email',
            'body' => 'An earlier nudge.',
            'meta' => ['source' => 'ai_sdr'],
            'occurred_at' => now()->subHour(),
        ]);

        $this->instance(LlmGateway::class, Mockery::mock(LlmGateway::class));
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(['sent' => 0, 'skipped' => 0, 'failed' => 0], $stats);
        $this->assertCount(0, $sender->sent);
    }

    #[Test]
    public function an_unusable_ai_reply_leaves_no_activity_and_sends_nothing(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $lead = $this->contactedLead($workspace->id);
        $this->fakeLlmGateway('I am sorry, I cannot write that.');
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(0, $stats['sent']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertCount(0, $sender->sent);
        $this->assertSame(0, LeadActivity::where('lead_id', $lead->id)->where('type', 'email')->count());
    }

    #[Test]
    public function one_failed_generation_does_not_stop_the_next_lead(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $this->contactedLead($workspace->id);
        $this->contactedLead($workspace->id);

        $calls = 0;

        $mock = Mockery::mock(LlmGateway::class);
        $mock->shouldReceive('chat')->andReturnUsing(function () use (&$calls) {
            $calls++;

            return new LlmResponse($calls === 1 ? 'not json' : $this->aiReply(), 100, 50, 'test-model', 12);
        });

        $this->instance(LlmGateway::class, $mock);
        $sender = $this->fakeSender();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(1, $stats['sent']);
        $this->assertSame(1, $stats['skipped']);
        $this->assertCount(1, $sender->sent);
        $this->assertSame(1, LeadActivity::where('type', 'email')->where('meta->source', 'ai_sdr')->count());
    }

    #[Test]
    public function the_system_sequence_is_hidden_from_the_sequence_list_and_plan_count(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->actor(['ai_sdr' => true, 'sequences' => true]);
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $this->contactedLead($workspace->id);
        $this->fakeLlmGateway($this->aiReply());
        $this->fakeSender();

        app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(1, Sequence::system($workspace->id)->count());
        $this->assertSame(0, Sequence::forWorkspace($workspace->id)->count());

        $props = $this->actingAs($user)
            ->get(route('client.sales.sequences.index'))
            ->assertOk()
            ->viewData('page')['props'];

        $this->assertSame(0, $props['usage']['current']);
        $this->assertSame([], $props['sequences']['data']);
    }

    #[Test]
    public function the_settings_page_and_update_are_gated_by_the_plan(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->actor();

        $this->actingAs($user)
            ->get(route('client.sales.ai-sdr.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('AiSdr/Index')
                ->where('settings.enabled', false)
                ->where('settings.channel', 'email')
            );

        $this->actingAs($user)
            ->put(route('client.sales.ai-sdr.update'), [
                'enabled' => true,
                'channel' => 'whatsapp',
                'daily_cap' => 5,
                'per_lead_cap' => 2,
                'min_hours_between' => 12,
            ])
            ->assertRedirect();

        $settings = AiSdrSettings::forWorkspace($workspace->id);

        $this->assertTrue($settings->enabled);
        $this->assertSame('whatsapp', $settings->channel);
        $this->assertSame(5, $settings->daily_cap);
        $this->assertSame(2, $settings->per_lead_cap);
        $this->assertSame(12, $settings->min_hours_between);
    }

    #[Test]
    public function a_plan_without_ai_sdr_cannot_open_the_settings_page(): void
    {
        ['user' => $user] = $this->actor(['ai_sdr' => false]);

        $this->actingAs($user)
            ->get(route('client.sales.ai-sdr.index'))
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('upgrade_required', true);
    }

    #[Test]
    public function running_now_queues_a_job_only_when_the_switch_is_on(): void
    {
        ['user' => $user, 'workspace' => $workspace] = $this->actor();

        Queue::fake();

        $this->actingAs($user)
            ->post(route('client.sales.ai-sdr.run'))
            ->assertRedirect()
            ->assertSessionHas('error');

        Queue::assertNotPushed(RunAiSdrJob::class);

        $this->settings($workspace->id, ['enabled' => true]);

        $this->actingAs($user)
            ->post(route('client.sales.ai-sdr.run'))
            ->assertRedirect()
            ->assertSessionHas('success');

        Queue::assertPushed(RunAiSdrJob::class, fn (RunAiSdrJob $job) => $job->workspaceId === $workspace->id);
    }

    #[Test]
    public function the_scheduled_command_queues_only_enabled_workspaces(): void
    {
        ['workspace' => $workspace, 'client' => $client] = $this->actor();

        $this->settings($workspace->id, ['enabled' => true]);

        // A second workspace that switched the AI SDR off must not be queued.
        ['workspace' => $other] = $this->createWorkspaceContext();
        $this->attachPlanToClient($client, Plan::factory()->create(['feature_flags' => ['ai_sdr' => true]]));
        $this->settings($other->id, ['enabled' => false]);

        Queue::fake();

        $this->artisan('ai-sdr:run')->assertSuccessful();

        Queue::assertPushed(RunAiSdrJob::class, 1);
        Queue::assertPushed(RunAiSdrJob::class, fn (RunAiSdrJob $job) => $job->workspaceId === $workspace->id);
        Queue::assertNotPushed(RunAiSdrJob::class, fn (RunAiSdrJob $job) => $job->workspaceId === $other->id);
    }

    #[Test]
    public function a_real_send_mirrors_the_message_into_the_inbox(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $lead = $this->contactedLead($workspace->id);
        $this->fakeLlmGateway($this->aiReply());

        Mail::fake();

        $before = Message::where('direction', 'out')->count();

        $stats = app(AiSdrEngine::class)->runWorkspace($workspace->id);

        $this->assertSame(1, $stats['sent']);
        // The sender mirrored the email into the same thread as the reply input.
        $this->assertSame($before + 1, Message::where('direction', 'out')->count());
        $this->assertTrue(
            Message::where('direction', 'out')
                ->where('sent_by', 'automation')
                ->whereHas('conversation', fn ($query) => $query->where('contact_id', $lead->contact->id))
                ->exists(),
        );
    }

    #[Test]
    public function usage_counts_what_the_engine_sent(): void
    {
        ['workspace' => $workspace] = $this->actor();
        $this->openAllWeek($workspace->id);
        $this->settings($workspace->id);

        $lead = $this->contactedLead($workspace->id);

        LeadActivity::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'type' => 'email',
            'body' => 'An earlier nudge.',
            'meta' => ['source' => 'ai_sdr'],
            'occurred_at' => now(),
        ]);

        LeadActivity::create([
            'workspace_id' => $workspace->id,
            'lead_id' => $lead->id,
            'type' => 'whatsapp',
            'body' => 'A WhatsApp nudge.',
            'meta' => ['source' => 'ai_sdr'],
            'occurred_at' => now()->subDays(3),
        ]);

        $this->assertSame(['today' => 1, 'total' => 2], app(AiSdrEngine::class)->usage($workspace->id));
    }
}
