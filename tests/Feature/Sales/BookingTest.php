<?php

namespace Tests\Feature\Sales;

use App\Mail\BookingReminderMail;
use App\Models\Plan;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Jobs\SendBookingReminderJob;
use App\Modules\Sales\Models\AvailabilityRule;
use App\Modules\Sales\Models\Booking;
use App\Modules\Sales\Models\MeetingType;
use App\Modules\Sales\Services\AvailabilityService;
use App\Modules\Sales\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actorWithPlan(bool $bookingFeature = true): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => [
                'booking' => $bookingFeature,
                'forms' => true,
            ],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
    }

    #[Test]
    public function booking_feature_gate_blocks_when_disabled(): void
    {
        [$user] = $this->actorWithPlan(bookingFeature: false);

        $response = $this->actingAs($user)->get(route('client.sales.booking.index'));
        $response->assertRedirect(route('client.pricing'));
        $response->assertSessionHas('upgrade_required', true);
    }

    #[Test]
    public function can_view_booking_index_and_seeds_default_discovery_call(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $response = $this->actingAs($user)->get(route('client.sales.booking.index'));
        $response->assertStatus(200);

        $this->assertDatabaseHas('meeting_types', [
            'workspace_id' => $workspace->id,
            'name' => 'Discovery Call',
            'duration_minutes' => 30,
        ]);
    }

    #[Test]
    public function can_create_meeting_type_with_unique_slug(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $response = $this->actingAs($user)->post(route('client.sales.booking.store'), [
            'name' => '45 Min Strategy Session',
            'duration_minutes' => 45,
            'location_type' => 'google_meet',
            'buffer_before' => 10,
            'buffer_after' => 10,
            'min_notice_hours' => 2,
            'booking_window_days' => 21,
            'color' => '#10b981',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('meeting_types', [
            'workspace_id' => $workspace->id,
            'name' => '45 Min Strategy Session',
            'slug' => '45-min-strategy-session',
            'duration_minutes' => 45,
        ]);
    }

    #[Test]
    public function can_update_meeting_type_and_status(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $type = MeetingType::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Initial Assessment',
            'duration_minutes' => 20,
            'location_type' => 'phone',
            'status' => 'active',
        ]);

        $response = $this->actingAs($user)->put(route('client.sales.booking.update', $type->id), [
            'name' => 'Comprehensive Assessment',
            'duration_minutes' => 30,
            'location_type' => 'google_meet',
            'status' => 'inactive',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('meeting_types', [
            'id' => $type->id,
            'name' => 'Comprehensive Assessment',
            'duration_minutes' => 30,
            'status' => 'inactive',
        ]);
    }

    #[Test]
    public function can_delete_meeting_type(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $type = MeetingType::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'To Be Removed',
            'duration_minutes' => 15,
            'location_type' => 'custom',
        ]);

        $response = $this->actingAs($user)->delete(route('client.sales.booking.destroy', $type->id));
        $response->assertRedirect();
        $this->assertDatabaseMissing('meeting_types', ['id' => $type->id]);
    }

    #[Test]
    public function can_update_user_availability_working_hours(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $response = $this->actingAs($user)->put(route('client.sales.booking.availability.update'), [
            'timezone' => 'Africa/Cairo',
            'rules' => [
                ['day_of_week' => 1, 'start_time' => '10:00:00', 'end_time' => '16:00:00'],
                ['day_of_week' => 2, 'start_time' => '10:00:00', 'end_time' => '16:00:00'],
            ],
        ]);

        $response->assertRedirect();
        $user->refresh();
        $this->assertSame('Africa/Cairo', $user->timezone);

        $this->assertDatabaseHas('availability_rules', [
            'user_id' => $user->id,
            'day_of_week' => 1,
            'start_time' => '10:00:00',
        ]);
        $this->assertDatabaseHas('availability_rules', [
            'user_id' => $user->id,
            'day_of_week' => 2,
            'start_time' => '10:00:00',
        ]);
    }

    #[Test]
    public function availability_service_calculates_slots_and_excludes_conflicts(): void
    {
        [$user, $workspace] = $this->actorWithPlan();
        $user->update(['timezone' => 'UTC']);

        // Set rule for day (say tomorrow)
        $tomorrow = Carbon::now('UTC')->addDay();
        $dayOfWeek = $tomorrow->dayOfWeek;

        AvailabilityRule::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'day_of_week' => $dayOfWeek,
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'timezone' => 'UTC',
        ]);

        $meetingType = MeetingType::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Demo 30 Min',
            'duration_minutes' => 30,
            'min_notice_hours' => 0,
            'booking_window_days' => 14,
        ]);

        $service = app(AvailabilityService::class);
        $slots = $service->getAvailableSlots($meetingType, $tomorrow->toDateString(), 'UTC');

        // From 10:00 to 12:00 with 30m slots => 4 slots: 10:00, 10:30, 11:00, 11:30
        $this->assertCount(4, $slots);

        // Book slot 10:30 - 11:00
        Booking::create([
            'workspace_id' => $workspace->id,
            'meeting_type_id' => $meetingType->id,
            'host_user_id' => $user->id,
            'starts_at' => Carbon::parse($tomorrow->toDateString().' 10:30:00', 'UTC'),
            'ends_at' => Carbon::parse($tomorrow->toDateString().' 11:00:00', 'UTC'),
            'status' => Booking::STATUS_CONFIRMED,
        ]);

        // Re-calculate: 10:30 slot should now be gone!
        $slotsAfterBooking = $service->getAvailableSlots($meetingType, $tomorrow->toDateString(), 'UTC');
        $this->assertCount(3, $slotsAfterBooking);

        $times = array_column($slotsAfterBooking, 'time_formatted');
        $this->assertNotContains('10:30 AM', $times);
    }

    #[Test]
    public function public_booking_flow_creates_booking_and_attaches_lead(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $meetingType = MeetingType::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'VIP Consultation',
            'slug' => 'vip-consultation',
            'duration_minutes' => 30,
            'status' => 'active',
        ]);

        // 1. View public page
        $viewResponse = $this->get(route('booking.public.show', $meetingType->slug));
        $viewResponse->assertStatus(200);

        // 2. Fetch slots via JSON
        $tomorrow = Carbon::now('UTC')->addDay()->toDateString();
        $slotsResponse = $this->getJson(route('booking.public.slots', [$meetingType->slug, 'date' => $tomorrow]));
        $slotsResponse->assertStatus(200);
        $slotsResponse->assertJsonStructure(['slots']);

        // 3. Submit booking
        $slotTime = Carbon::now('UTC')->addDays(2)->setHour(14)->setMinute(0)->setSecond(0);

        $bookingData = [
            'starts_at' => $slotTime->toIso8601String(),
            'guest_name' => 'Michael Corleone',
            'guest_email' => 'michael@corleone.it',
            'guest_phone' => '+39061234567',
            'guest_timezone' => 'Europe/Rome',
        ];

        $postResponse = $this->post(route('booking.public.submit', $meetingType->slug), $bookingData);
        $postResponse->assertRedirect();

        // Assert booking in DB
        $booking = Booking::where('guest_email', 'michael@corleone.it')->first();
        $this->assertNotNull($booking);
        $this->assertSame('Michael Corleone', $booking->guest_name);
        $this->assertNotNull($booking->meet_link);
        $this->assertNotNull($booking->cancel_token);

        // Assert lead created in workspace
        $lead = Lead::where('email', 'michael@corleone.it')->first();
        $this->assertNotNull($lead);
        $this->assertSame($workspace->id, $lead->workspace_id);
        $this->assertSame('Booking: VIP Consultation', $lead->source);
        $this->assertSame($booking->lead_id, $lead->id);

        // Assert lead activity logged
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id,
            'type' => 'meeting',
        ]);
    }

    #[Test]
    public function double_booking_same_slot_is_prevented(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $meetingType = MeetingType::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Product Demo',
            'duration_minutes' => 30,
            'status' => 'active',
        ]);

        $slotTime = Carbon::now('UTC')->addDays(3)->setHour(11)->setMinute(0)->setSecond(0);

        $service = app(BookingService::class);

        // Booking 1
        $service->createBooking($meetingType, [
            'starts_at' => $slotTime->toIso8601String(),
            'guest_name' => 'First Guest',
            'guest_email' => 'first@example.com',
        ]);

        // Booking 2 at the exact same time should throw ValidationException
        $this->expectException(ValidationException::class);
        $service->createBooking($meetingType, [
            'starts_at' => $slotTime->toIso8601String(),
            'guest_name' => 'Second Guest',
            'guest_email' => 'second@example.com',
        ]);
    }

    #[Test]
    public function booking_can_be_cancelled_via_token(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $meetingType = MeetingType::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Quick Chat',
            'duration_minutes' => 15,
            'status' => 'active',
        ]);

        $slotTime = Carbon::now('UTC')->addDays(2)->setHour(15)->setMinute(0)->setSecond(0);
        $booking = app(BookingService::class)->createBooking($meetingType, [
            'starts_at' => $slotTime->toIso8601String(),
            'guest_name' => 'Tom Hagen',
            'guest_email' => 'tom@corleone.it',
        ]);

        $this->assertSame(Booking::STATUS_CONFIRMED, $booking->status);

        $response = $this->postJson(route('booking.public.cancel', $booking->cancel_token), [
            'reason' => 'Need to attend an urgent meeting in Las Vegas',
        ]);

        $response->assertStatus(200);
        $booking->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame('Need to attend an urgent meeting in Las Vegas', $booking->cancel_reason);
    }

    #[Test]
    public function workspace_isolation_for_booking_pages(): void
    {
        [$user1, $workspace1] = $this->actorWithPlan();
        [$user2, $workspace2] = $this->actorWithPlan();

        $type1 = MeetingType::create([
            'workspace_id' => $workspace1->id,
            'user_id' => $user1->id,
            'name' => 'Workspace 1 Meeting',
            'duration_minutes' => 30,
        ]);

        // User 2 cannot edit or delete User 1's meeting type
        $response = $this->actingAs($user2)->put(route('client.sales.booking.update', $type1->id), [
            'name' => 'Hijacked Meeting',
            'duration_minutes' => 30,
            'location_type' => 'google_meet',
            'status' => 'active',
        ]);
        $response->assertStatus(403);

        $deleteResponse = $this->actingAs($user2)->delete(route('client.sales.booking.destroy', $type1->id));
        $deleteResponse->assertStatus(403);
    }

    #[Test]
    public function booking_reminder_command_queues_only_upcoming_confirmed_bookings(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $meetingType = MeetingType::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Discovery Call',
            'slug' => 'discovery-call',
            'duration_minutes' => 30,
            'status' => 'active',
        ]);

        $upcoming = $this->makeBooking($meetingType, $user, now()->addHours(2));
        $tooFarAway = $this->makeBooking($meetingType, $user, now()->addHours(48));
        $cancelled = $this->makeBooking($meetingType, $user, now()->addHours(2), Booking::STATUS_CANCELLED);

        Queue::fake();

        $this->artisan('bookings:send-reminders --hours=24')
            ->expectsOutputToContain('Dispatched 1 booking reminder job(s).')
            ->assertSuccessful();

        Queue::assertPushed(SendBookingReminderJob::class, function (SendBookingReminderJob $job) use ($upcoming) {
            return $job->booking->id === $upcoming->id;
        });

        Queue::assertNotPushed(SendBookingReminderJob::class, function (SendBookingReminderJob $job) use ($tooFarAway) {
            return $job->booking->id === $tooFarAway->id;
        });

        Queue::assertNotPushed(SendBookingReminderJob::class, function (SendBookingReminderJob $job) use ($cancelled) {
            return $job->booking->id === $cancelled->id;
        });
    }

    #[Test]
    public function booking_reminder_job_emails_the_guest_once_and_marks_the_booking(): void
    {
        [$user, $workspace] = $this->actorWithPlan();

        $meetingType = MeetingType::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'name' => 'Discovery Call',
            'slug' => 'discovery-call',
            'duration_minutes' => 30,
            'status' => 'active',
        ]);

        $booking = $this->makeBooking($meetingType, $user, now()->addHours(2));

        // The blade view must render with the booking's details before the job
        // queues it; a broken view would otherwise only surface in production.
        $rendered = (new BookingReminderMail($booking))->render();
        $this->assertStringContainsString('Discovery Call', $rendered);
        $this->assertStringContainsString($booking->meet_link, $rendered);

        Mail::fake();

        (new SendBookingReminderJob($booking))->handle();

        Mail::assertQueued(BookingReminderMail::class, function (BookingReminderMail $mail) use ($booking) {
            return $mail->hasTo($booking->guest_email);
        });

        $booking->refresh();
        $this->assertNotNull($booking->reminder_sent_at);

        // Running again must not email the same guest twice.
        (new SendBookingReminderJob($booking))->handle();

        Mail::assertQueued(BookingReminderMail::class, 1);
    }

    private function makeBooking(
        MeetingType $meetingType,
        User $host,
        \DateTimeInterface $startsAt,
        string $status = Booking::STATUS_CONFIRMED,
    ): Booking {
        $starts = Carbon::parse($startsAt);

        return Booking::create([
            'workspace_id' => $meetingType->workspace_id,
            'meeting_type_id' => $meetingType->id,
            'host_user_id' => $host->id,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes($meetingType->duration_minutes),
            'status' => $status,
            'guest_name' => 'Michael Corleone',
            'guest_email' => 'michael@corleone.it',
            'guest_timezone' => 'UTC',
        ]);
    }
}
