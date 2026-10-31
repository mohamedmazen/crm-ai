<?php

namespace Tests\Feature\Leads;

use App\Events\CommerceEventReceived;
use App\Events\FormSubmitted;
use App\Events\MessageReceived;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Modules\Broadcasting\Models\Campaign;
use App\Modules\Broadcasting\Models\CampaignRecipient;
use App\Modules\Leads\Contracts\LeadScorer;
use App\Modules\Leads\Jobs\DecayLeadScoresJob;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScoreEvent;
use App\Modules\Leads\Services\Scoring\BehavioralScorer;
use App\Modules\Sales\Models\MeetingType;
use App\Modules\Sales\Services\BookingService;
use App\Modules\Shared\Models\Contact;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Phase 3.3 behavioral lead scoring: engagement points on top of the rule score,
 * the event hooks that feed them, and the nightly inactivity decay.
 *
 * The whole feature sits behind `feature:sales_reports`, so the gate-off case is
 * asserted alongside every behavior that matters.
 */
class BehavioralScoringTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function actor(bool $enabled = true): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => ['sales_reports' => $enabled],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace];
    }

    /**
     * A lead whose rule-based signals are neutral, so its score is exactly the
     * behavioral total and assertions do not have to net out the scraper rules.
     */
    private function lead(Workspace $workspace, array $attrs = []): Lead
    {
        return Lead::factory()->create($attrs + [
            'workspace_id' => $workspace->id,
            'phone' => null,
            'website' => null,
            'rating' => null,
            'review_count' => 0,
        ]);
    }

    private function scorer(): BehavioralScorer
    {
        return app(BehavioralScorer::class);
    }

    #[Test]
    public function recording_an_event_writes_the_row_and_rescores_the_lead(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        $entry = $this->scorer()->record($lead, 'message_reply', 'Replied');

        $this->assertNotNull($entry);
        $this->assertDatabaseHas('lead_score_events', [
            'lead_id' => $lead->id,
            'event' => 'message_reply',
            'points' => 10,
            'reason' => 'Replied',
        ]);

        $lead->refresh();
        $this->assertSame(10, $lead->score);
        $this->assertSame('cold', $lead->score_band);
        $this->assertNotNull($lead->scored_at);
        $this->assertNotNull($lead->last_activity_at);
    }

    #[Test]
    public function behavioral_points_are_added_on_top_of_the_rule_score(): void
    {
        [, $workspace] = $this->actor();

        // A lead that maxes the scraper rules is already at 100; engagement cannot
        // push it past the ceiling.
        $maxed = $this->lead($workspace, [
            'phone' => '+8801700000001',
            'website' => 'https://example.com',
            'rating' => 5,
            'review_count' => 500,
        ]);
        $this->scorer()->record($maxed, 'order_placed');
        $this->assertSame(100, $maxed->fresh()->score);

        // A neutral lead takes the full weight of the event.
        $plain = $this->lead($workspace);
        $this->scorer()->record($plain, 'booking_created');
        $this->assertSame(20, $plain->fresh()->score);
    }

    #[Test]
    public function a_lead_without_events_keeps_the_plain_rule_breakdown(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        $breakdown = collect(app(LeadScorer::class)->score($lead)->breakdown);

        $this->assertSame(
            ['has_phone', 'rating', 'review_volume', 'has_website'],
            $breakdown->pluck('rule')->all()
        );
    }

    #[Test]
    public function the_gate_blocks_all_behavioral_recording(): void
    {
        [, $workspace] = $this->actor(false);
        $lead = $this->lead($workspace);

        $entry = $this->scorer()->record($lead, 'message_reply');

        $this->assertNull($entry);
        $this->assertSame(0, LeadScoreEvent::where('lead_id', $lead->id)->count());
        $this->assertNull($lead->fresh()->score);
        $this->assertNull($lead->fresh()->last_activity_at);
    }

    #[Test]
    public function a_customer_reply_awards_ten_points(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        $this->message($workspace, $lead, 'Hello, is anyone there?');

        $this->assertDatabaseHas('lead_score_events', ['lead_id' => $lead->id, 'event' => 'message_reply', 'points' => 10]);
        $this->assertSame(10, $lead->fresh()->score);
    }

    #[Test]
    public function a_pricing_question_also_awards_the_pricing_bonus(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        $this->message($workspace, $lead, 'How much does this cost?');

        $this->assertDatabaseHas('lead_score_events', ['lead_id' => $lead->id, 'event' => 'message_reply']);
        $this->assertDatabaseHas('lead_score_events', ['lead_id' => $lead->id, 'event' => 'price_inquiry', 'points' => 15]);
        $this->assertSame(25, $lead->fresh()->score);
    }

    #[Test]
    public function an_outbound_message_scores_nothing(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        $this->message($workspace, $lead, 'Our pricing page is here', 'out');

        $this->assertSame(0, LeadScoreEvent::where('lead_id', $lead->id)->count());
    }

    #[Test]
    public function a_form_submission_awards_ten_points(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace);

        FormSubmitted::dispatch($workspace->id, 7, $lead->id, null, 'Contact Us', 55);

        $this->assertDatabaseHas('lead_score_events', [
            'lead_id' => $lead->id,
            'event' => 'form_submitted',
            'points' => 10,
        ]);
    }

    #[Test]
    public function a_placed_order_awards_points_but_other_commerce_events_do_not(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'lead_id' => $lead->id]);

        CommerceEventReceived::dispatch($workspace->id, $contact->id, 'order.placed', ['order_id' => 'ord-1']);
        CommerceEventReceived::dispatch($workspace->id, $contact->id, 'order.fulfilled', ['order_id' => 'ord-1']);

        $this->assertSame(1, LeadScoreEvent::where('lead_id', $lead->id)->count());
        $this->assertDatabaseHas('lead_score_events', ['lead_id' => $lead->id, 'event' => 'order_placed', 'points' => 25]);
    }

    #[Test]
    public function a_booking_awards_twenty_points(): void
    {
        Mail::fake();
        [$user, $workspace] = $this->actor();

        $meetingType = MeetingType::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Demo',
            'duration_minutes' => 30,
            'status' => 'active',
        ]);

        $booking = app(BookingService::class)->createBooking($meetingType, [
            'starts_at' => Carbon::now('UTC')->addDays(3)->setHour(11)->setMinute(0)->setSecond(0)->toIso8601String(),
            'guest_name' => 'Layla Hassan',
            'guest_email' => 'layla@example.com',
        ]);

        $this->assertDatabaseHas('lead_score_events', [
            'lead_id' => $booking->lead_id,
            'event' => 'booking_created',
            'points' => 20,
        ]);
        $this->assertSame(20, Lead::find($booking->lead_id)->score);
    }

    #[Test]
    public function a_campaign_open_and_click_each_score_only_once(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace);
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'lead_id' => $lead->id]);
        $campaign = Campaign::factory()->create(['workspace_id' => $workspace->id]);

        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'status' => 'sent',
            'tracking_token' => 'tok-open-click',
        ]);

        $headers = ['User-Agent' => 'Mozilla/5.0'];

        $this->withHeaders($headers)->get(route('track.email.open', ['token' => 'tok-open-click']));
        $this->withHeaders($headers)->get(route('track.email.open', ['token' => 'tok-open-click']));

        $click = URL::signedRoute('track.email.click', ['token' => 'tok-open-click', 'url' => 'https://example.com']);
        $this->withHeaders($headers)->get($click);
        $this->withHeaders($headers)->get($click);

        $this->assertSame(1, LeadScoreEvent::where('lead_id', $lead->id)->where('event', 'campaign_open')->count());
        $this->assertSame(1, LeadScoreEvent::where('lead_id', $lead->id)->where('event', 'campaign_click')->count());
        $this->assertSame(8, $lead->fresh()->score);
    }

    #[Test]
    public function an_inactive_lead_is_decayed_once_per_tier(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace, ['last_activity_at' => now()->subDays(8)]);

        DecayLeadScoresJob::dispatchSync($workspace->id);
        DecayLeadScoresJob::dispatchSync($workspace->id);

        $this->assertSame(1, LeadScoreEvent::where('lead_id', $lead->id)->where('event', 'inactivity_7d')->count());

        // Crossing the 30-day line adds the heavier tier, but never repeats it.
        $lead->update(['last_activity_at' => now()->subDays(40)]);
        DecayLeadScoresJob::dispatchSync($workspace->id);
        DecayLeadScoresJob::dispatchSync($workspace->id);

        $decays = LeadScoreEvent::where('lead_id', $lead->id)
            ->whereIn('event', ['inactivity_7d', 'inactivity_30d'])
            ->get();

        $this->assertCount(2, $decays);
        $this->assertSame(-20, (int) $decays->sum('points'));
        $this->assertSame(0, $lead->fresh()->score);
    }

    #[Test]
    public function a_new_engagement_re_arms_decay(): void
    {
        [, $workspace] = $this->actor();
        $lead = $this->lead($workspace, ['last_activity_at' => now()->subDays(8)]);

        DecayLeadScoresJob::dispatchSync($workspace->id);

        // The reply refreshes last_activity_at, so the same tier can be charged
        // again only after another full week of silence.
        $this->scorer()->record($lead, 'message_reply');
        DecayLeadScoresJob::dispatchSync($workspace->id);

        $this->assertSame(1, LeadScoreEvent::where('lead_id', $lead->id)->where('event', 'inactivity_7d')->count());
        $this->assertSame(5, $lead->fresh()->score);
    }

    #[Test]
    public function decay_skips_won_and_lost_leads(): void
    {
        [, $workspace] = $this->actor();
        $this->lead($workspace, ['last_activity_at' => now()->subDays(40), 'won_at' => now()]);
        $this->lead($workspace, ['last_activity_at' => now()->subDays(40), 'lost_at' => now()]);

        DecayLeadScoresJob::dispatchSync($workspace->id);

        $this->assertSame(0, LeadScoreEvent::count());
    }

    #[Test]
    public function the_decay_command_only_queues_workspaces_with_the_feature(): void
    {
        Queue::fake();

        [, $enabled] = $this->actor(true);
        [, $disabled] = $this->actor(false);

        $this->artisan('leads:decay-scores')->assertExitCode(0);

        Queue::assertPushed(DecayLeadScoresJob::class, 1);
        Queue::assertPushed(DecayLeadScoresJob::class, fn (DecayLeadScoresJob $job) => $job->workspaceId === $enabled->id);
        Queue::assertNotPushed(DecayLeadScoresJob::class, fn (DecayLeadScoresJob $job) => $job->workspaceId === $disabled->id);
    }

    /**
     * Persist an inbound (or outbound) message for a lead's contact and dispatch
     * the event the scoring listener is registered on.
     */
    private function message(Workspace $workspace, Lead $lead, string $body, string $direction = 'in'): void
    {
        $contact = Contact::factory()->create(['workspace_id' => $workspace->id, 'lead_id' => $lead->id]);
        $conversation = Conversation::create([
            'workspace_id' => $workspace->id,
            'contact_id' => $contact->id,
            'status' => 'open',
        ]);

        $message = Message::create([
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'channel' => 'whatsapp',
            'type' => 'text',
            'body' => $body,
        ]);

        MessageReceived::dispatch($message);
    }
}
