<?php

namespace Tests\Feature\Sales;

use App\Mail\BookingRescheduledMail;
use App\Models\Plan;
use App\Models\User;
use App\Modules\Sales\Models\Booking;
use App\Modules\Sales\Models\MeetingType;
use App\Modules\Sales\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingRescheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actorWithPlan(): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => ['booking' => true],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
    }

    private function makeMeetingType(User $host, array $overrides = []): MeetingType
    {
        return MeetingType::create(array_merge([
            'workspace_id' => $host->workspace_id,
            'user_id' => $host->id,
            'name' => 'Discovery Call',
            'slug' => 'discovery-call-'.uniqid(),
            'duration_minutes' => 30,
            'buffer_before' => 0,
            'buffer_after' => 0,
            'min_notice_hours' => 0,
            'booking_window_days' => 30,
            'status' => 'active',
        ], $overrides));
    }

    private function makeBooking(
        MeetingType $meetingType,
        User $host,
        \DateTimeInterface $startsAt,
        string $status = Booking::STATUS_CONFIRMED,
        array $overrides = [],
    ): Booking {
        $starts = Carbon::parse($startsAt);

        return Booking::create(array_merge([
            'workspace_id' => $meetingType->workspace_id,
            'meeting_type_id' => $meetingType->id,
            'host_user_id' => $host->id,
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addMinutes($meetingType->duration_minutes),
            'status' => $status,
            'guest_name' => 'Michael Corleone',
            'guest_email' => 'michael@corleone.it',
            'guest_timezone' => 'UTC',
        ], $overrides));
    }

    #[Test]
    public function reschedule_page_renders_for_a_confirmed_booking(): void
    {
        [$user] = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(2)->setTime(10, 0));

        $response = $this->get(route('booking.public.reschedule.show', $booking->reschedule_token));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Booking/Reschedule')
            ->where('booking.reschedule_token', $booking->reschedule_token)
            ->where('meeting_type.slug', $meetingType->slug)
        );
    }

    #[Test]
    public function reschedule_page_is_not_found_for_unknown_token(): void
    {
        $this->actorWithPlan();

        $this->get(route('booking.public.reschedule.show', 'does-not-exist'))->assertNotFound();
    }

    #[Test]
    public function reschedule_page_is_not_found_for_a_cancelled_booking(): void
    {
        [$user] = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(2)->setTime(10, 0), Booking::STATUS_CANCELLED);

        $this->get(route('booking.public.reschedule.show', $booking->reschedule_token))->assertNotFound();
    }

    #[Test]
    public function guest_can_move_the_booking_and_the_lead_timeline_is_updated(): void
    {
        [$user] = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);

        // Create through the service so the booking is linked to a lead, as it
        // would be when a guest first books.
        $booking = app(BookingService::class)->createBooking($meetingType, [
            'starts_at' => Carbon::now()->addDays(2)->setTime(10, 0)->toIso8601String(),
            'guest_name' => 'Michael Corleone',
            'guest_email' => 'michael@corleone.it',
            'guest_timezone' => 'UTC',
        ]);
        $this->assertNotNull($booking->lead_id);

        Mail::fake();

        $newStart = Carbon::now()->addDays(3)->setTime(14, 0, 0);

        $response = $this->post(route('booking.public.reschedule', $booking->reschedule_token), [
            'starts_at' => $newStart->toIso8601String(),
            'guest_timezone' => 'UTC',
        ]);

        $response->assertRedirect(route('booking.public.confirmed', [$meetingType->slug, $booking->uuid]));

        $booking->refresh();
        $this->assertSame($newStart->getTimestamp(), $booking->starts_at->getTimestamp());
        $this->assertSame(
            $newStart->copy()->addMinutes(30)->getTimestamp(),
            $booking->ends_at->getTimestamp()
        );

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $booking->lead_id,
            'type' => 'meeting',
        ]);

        Mail::assertQueued(BookingRescheduledMail::class, function (BookingRescheduledMail $mail) use ($booking) {
            return $mail->hasTo($booking->guest_email);
        });
    }

    #[Test]
    public function rescheduling_arms_a_fresh_reminder_for_the_new_time(): void
    {
        [$user] = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(2)->setTime(10, 0), overrides: [
            'reminder_sent_at' => now(),
        ]);

        Mail::fake();

        app(BookingService::class)->rescheduleBooking($booking, [
            'starts_at' => Carbon::now()->addDays(4)->setTime(9, 0)->toIso8601String(),
        ]);

        $booking->refresh();
        $this->assertNull($booking->reminder_sent_at);
    }

    #[Test]
    public function rescheduling_into_a_taken_slot_is_rejected(): void
    {
        [$user] = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);

        $originalStart = Carbon::now()->addDays(2)->setTime(10, 0);
        $booking = $this->makeBooking($meetingType, $user, $originalStart);

        $takenStart = Carbon::now()->addDays(3)->setTime(15, 0, 0);
        $this->makeBooking($meetingType, $user, $takenStart);

        $response = $this->postJson(route('booking.public.reschedule', $booking->reschedule_token), [
            'starts_at' => $takenStart->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('starts_at');

        // The original slot is untouched.
        $booking->refresh();
        $this->assertSame($originalStart->getTimestamp(), $booking->starts_at->getTimestamp());
    }

    #[Test]
    public function rescheduling_to_a_past_time_is_rejected(): void
    {
        [$user] = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(2)->setTime(10, 0));

        $response = $this->postJson(route('booking.public.reschedule', $booking->reschedule_token), [
            'starts_at' => Carbon::now()->subDay()->toIso8601String(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('starts_at');
    }

    #[Test]
    public function service_refuses_to_reschedule_a_non_confirmed_booking(): void
    {
        [$user] = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(2)->setTime(10, 0), Booking::STATUS_CANCELLED);

        $this->expectException(ValidationException::class);

        app(BookingService::class)->rescheduleBooking($booking, [
            'starts_at' => Carbon::now()->addDays(3)->setTime(14, 0)->toIso8601String(),
        ]);
    }

    #[Test]
    public function rescheduled_mail_renders_with_the_new_time(): void
    {
        [$user] = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(5)->setTime(11, 0));

        $rendered = (new BookingRescheduledMail($booking))->render();

        $this->assertStringContainsString('Discovery Call', $rendered);
        $this->assertStringContainsString($booking->meet_link, $rendered);
    }
}
