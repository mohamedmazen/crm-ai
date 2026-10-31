<?php

namespace Tests\Feature\Sales;

use App\Mail\BookingConfirmedMail;
use App\Models\Plan;
use App\Models\User;
use App\Modules\Sales\Models\Booking;
use App\Modules\Sales\Models\MeetingType;
use App\Modules\Sales\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BookingConfirmedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actorWithPlan(): User
    {
        ['user' => $user, 'client' => $client] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => ['booking' => true],
        ]);

        $this->attachPlanToClient($client, $plan);

        return $user;
    }

    private function makeMeetingType(User $host, array $overrides = []): MeetingType
    {
        return MeetingType::create(array_merge([
            'workspace_id' => $host->workspace_id,
            'user_id' => $host->id,
            'name' => 'Strategy Session',
            'slug' => 'strategy-session-'.uniqid(),
            'duration_minutes' => 45,
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
            'guest_name' => 'Vito Corleone',
            'guest_email' => 'vito@corleone.it',
            'guest_timezone' => 'UTC',
        ], $overrides));
    }

    #[Test]
    public function a_confirmation_email_is_queued_when_a_guest_books(): void
    {
        $user = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);

        Mail::fake();

        $booking = app(BookingService::class)->createBooking($meetingType, [
            'starts_at' => Carbon::now()->addDays(2)->setTime(10, 0)->toIso8601String(),
            'guest_name' => 'Vito Corleone',
            'guest_email' => 'vito@corleone.it',
            'guest_timezone' => 'UTC',
        ]);

        Mail::assertQueued(BookingConfirmedMail::class, function (BookingConfirmedMail $mail) {
            return $mail->hasTo('vito@corleone.it');
        });

        // The guest's confirmation links are valid tokens on the booking.
        $this->assertNotEmpty($booking->cancel_token);
        $this->assertNotEmpty($booking->reschedule_token);
    }

    #[Test]
    public function no_confirmation_email_is_sent_without_a_guest_email(): void
    {
        $user = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);

        Mail::fake();

        app(BookingService::class)->createBooking($meetingType, [
            'starts_at' => Carbon::now()->addDays(2)->setTime(10, 0)->toIso8601String(),
            'guest_name' => 'Walk In',
            'guest_email' => null,
            'guest_timezone' => 'UTC',
        ]);

        Mail::assertNotQueued(BookingConfirmedMail::class);
    }

    #[Test]
    public function the_confirmation_email_contains_the_meet_and_guest_links(): void
    {
        $user = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(3)->setTime(11, 0));

        $rendered = (new BookingConfirmedMail($booking->load('meetingType', 'host')))->render();

        $this->assertStringContainsString('Strategy Session', $rendered);
        $this->assertStringContainsString($booking->meet_link, $rendered);
        $this->assertStringContainsString(route('booking.public.cancel.show', $booking->cancel_token), $rendered);
        $this->assertStringContainsString(route('booking.public.reschedule.show', $booking->reschedule_token), $rendered);
    }

    #[Test]
    public function the_cancel_page_renders_for_a_confirmed_booking(): void
    {
        $user = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(2)->setTime(10, 0));

        $response = $this->get(route('booking.public.cancel.show', $booking->cancel_token));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Booking/Cancel')
            ->where('booking.cancel_token', $booking->cancel_token)
            ->where('booking.status', Booking::STATUS_CONFIRMED)
            ->where('meeting_type.name', 'Strategy Session')
            ->where('host.name', $user->name)
        );
    }

    #[Test]
    public function the_cancel_page_reports_an_already_cancelled_booking(): void
    {
        $user = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(2)->setTime(10, 0), Booking::STATUS_CANCELLED);

        $response = $this->get(route('booking.public.cancel.show', $booking->cancel_token));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Booking/Cancel')
            ->where('booking.status', Booking::STATUS_CANCELLED)
        );
    }

    #[Test]
    public function the_cancel_page_is_not_found_for_an_unknown_token(): void
    {
        $this->actorWithPlan();

        $this->get(route('booking.public.cancel.show', 'does-not-exist'))->assertNotFound();
    }

    #[Test]
    public function a_guest_can_cancel_the_booking_from_the_token_link(): void
    {
        $user = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(2)->setTime(10, 0));

        $response = $this->post(route('booking.public.cancel', $booking->cancel_token), [
            'reason' => 'Something came up',
        ]);

        $response->assertRedirect();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertNotNull($booking->cancelled_at);
        $this->assertSame('Something came up', $booking->cancel_reason);
    }

    #[Test]
    public function a_json_cancel_returns_success(): void
    {
        $user = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(2)->setTime(10, 0));

        $response = $this->postJson(route('booking.public.cancel', $booking->cancel_token));

        $response->assertStatus(200);
        $response->assertJson(['success' => true]);

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->fresh()->status);
    }

    #[Test]
    public function the_confirmed_page_exposes_the_cancel_link(): void
    {
        $user = $this->actorWithPlan();
        $meetingType = $this->makeMeetingType($user);
        $booking = $this->makeBooking($meetingType, $user, now()->addDays(2)->setTime(10, 0));

        $response = $this->get(route('booking.public.confirmed', [$meetingType->slug, $booking->uuid]));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->component('Booking/Confirmed')
            ->where('booking.cancel_token', $booking->cancel_token)
        );
    }
}
