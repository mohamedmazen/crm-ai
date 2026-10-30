<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Models\Booking;
use App\Modules\Sales\Models\MeetingType;
use App\Modules\Sales\Services\AvailabilityService;
use App\Modules\Sales\Services\BookingService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BookingPublicController extends Controller
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
        private readonly BookingService $bookingService,
    ) {}

    /**
     * Display public scheduling page for a meeting type.
     */
    public function show(Request $request, string $slug): Response
    {
        $meetingType = MeetingType::where('slug', $slug)
            ->where('status', MeetingType::STATUS_ACTIVE)
            ->with(['user:id,name,email,avatar,timezone'])
            ->firstOrFail();

        $host = $meetingType->user ?? User::where('workspace_id', $meetingType->workspace_id)->first();

        return Inertia::render('Booking/PublicShow', [
            'meeting_type' => [
                'id' => $meetingType->id,
                'uuid' => $meetingType->uuid,
                'name' => $meetingType->name,
                'slug' => $meetingType->slug,
                'description' => $meetingType->description,
                'duration_minutes' => $meetingType->duration_minutes,
                'location_type' => $meetingType->location_type,
                'location_details' => $meetingType->location_details,
                'color' => $meetingType->color,
                'booking_window_days' => $meetingType->booking_window_days,
                'min_notice_hours' => $meetingType->min_notice_hours,
                'questions' => $meetingType->questions ?? [],
            ],
            'host' => [
                'name' => $host->name ?? 'Meeting Host',
                'email' => $host->email ?? '',
                'avatar' => $host->avatar ?? null,
                'timezone' => $host->timezone ?? 'UTC',
            ],
        ]);
    }

    /**
     * Fetch open time slots for a chosen date and guest timezone.
     */
    public function slots(Request $request, string $slug): JsonResponse
    {
        $meetingType = MeetingType::where('slug', $slug)
            ->where('status', MeetingType::STATUS_ACTIVE)
            ->firstOrFail();

        $dateString = $request->query('date', now()->toDateString());
        $guestTimezone = $request->query('timezone', 'UTC');

        $slots = $this->availabilityService->getAvailableSlots($meetingType, $dateString, $guestTimezone);

        return response()->json([
            'slots' => $slots,
        ]);
    }

    /**
     * Submit booking and reserve time slot.
     */
    public function store(Request $request, string $slug): JsonResponse|RedirectResponse
    {
        $meetingType = MeetingType::where('slug', $slug)
            ->where('status', MeetingType::STATUS_ACTIVE)
            ->firstOrFail();

        $validated = $request->validate([
            'starts_at' => ['required', 'string'],
            'guest_name' => ['required', 'string', 'max:128'],
            'guest_email' => ['required', 'email', 'max:256'],
            'guest_phone' => ['nullable', 'string', 'max:32'],
            'guest_timezone' => ['nullable', 'string', 'max:64'],
            'answers' => ['nullable', 'array'],
        ]);

        $booking = $this->bookingService->createBooking($meetingType, $validated);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'booking_uuid' => $booking->uuid,
                'redirect_url' => route('booking.public.confirmed', [$meetingType->slug, $booking->uuid]),
            ], 201);
        }

        return redirect()->route('booking.public.confirmed', [$meetingType->slug, $booking->uuid]);
    }

    /**
     * Display booking confirmation screen.
     */
    public function confirmed(string $slug, string $uuid): Response
    {
        $booking = Booking::where('uuid', $uuid)
            ->with(['meetingType', 'host:id,name,email,avatar,timezone'])
            ->firstOrFail();

        // Build Google Calendar Add Link
        $startIso = Carbon::parse($booking->starts_at)->format('Ymd\THis\Z');
        $endIso = Carbon::parse($booking->ends_at)->format('Ymd\THis\Z');
        $title = urlencode($booking->meetingType->name ?? 'Meeting');
        $details = urlencode('Meeting with ' . ($booking->host->name ?? 'Host') . ($booking->meet_link ? "\nJoin: " . $booking->meet_link : ''));
        $location = urlencode($booking->meet_link ?: $booking->meetingType->location_details ?: 'Online');

        $googleCalendarUrl = "https://calendar.google.com/calendar/render?action=TEMPLATE&text={$title}&dates={$startIso}/{$endIso}&details={$details}&location={$location}";

        return Inertia::render('Booking/Confirmed', [
            'booking' => [
                'uuid' => $booking->uuid,
                'starts_at' => $booking->starts_at->toIso8601String(),
                'ends_at' => $booking->ends_at->toIso8601String(),
                'status' => $booking->status,
                'meet_link' => $booking->meet_link,
                'guest_name' => $booking->guest_name,
                'guest_email' => $booking->guest_email,
                'guest_timezone' => $booking->guest_timezone,
                'cancel_token' => $booking->cancel_token,
            ],
            'meeting_type' => [
                'name' => $booking->meetingType->name,
                'duration_minutes' => $booking->meetingType->duration_minutes,
                'location_type' => $booking->meetingType->location_type,
            ],
            'host' => [
                'name' => $booking->host->name,
                'email' => $booking->host->email,
            ],
            'google_calendar_url' => $googleCalendarUrl,
        ]);
    }

    /**
     * Cancel booking via token.
     */
    public function cancel(Request $request, string $token): JsonResponse|RedirectResponse
    {
        $booking = Booking::where('cancel_token', $token)->firstOrFail();

        $reason = $request->input('reason', 'Cancelled by guest');
        $this->bookingService->cancelBooking($booking, $reason);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => __('Booking cancelled successfully.'),
            ]);
        }

        return back()->with('success', __('Booking cancelled successfully.'));
    }
}

