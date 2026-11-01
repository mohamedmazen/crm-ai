<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Sales\Models\AvailabilityOverride;
use App\Modules\Sales\Models\AvailabilityRule;
use App\Modules\Sales\Models\Booking;
use App\Modules\Sales\Models\MeetingType;
use App\Modules\Sales\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MeetingTypeController extends Controller
{
    public function __construct(
        private readonly BookingService $bookingService,
    ) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);
        $user = $request->user();

        // 1. Meeting Types
        $meetingTypes = MeetingType::where('workspace_id', $wid)
            ->with(['user:id,name,email,avatar'])
            ->withCount(['bookings as total_bookings_count'])
            ->withCount(['bookings as upcoming_bookings_count' => function ($q) {
                $q->where('status', Booking::STATUS_CONFIRMED)
                    ->where('starts_at', '>=', now());
            }])
            ->latest()
            ->get();

        // Auto-seed a default 30-min meeting type if empty
        if ($meetingTypes->isEmpty()) {
            $defaultMeeting = MeetingType::create([
                'workspace_id' => $wid,
                'user_id' => $user->id,
                'name' => 'Discovery Call',
                'description' => 'A 30-minute introductory meeting to explore our solutions.',
                'duration_minutes' => 30,
                'location_type' => MeetingType::LOCATION_GOOGLE_MEET,
                'buffer_before' => 5,
                'buffer_after' => 5,
                'min_notice_hours' => 4,
                'booking_window_days' => 14,
                'color' => '#6366f1',
                'status' => MeetingType::STATUS_ACTIVE,
            ]);

            $meetingTypes = collect([$defaultMeeting->load('user')->setAttribute('total_bookings_count', 0)->setAttribute('upcoming_bookings_count', 0)]);
        }

        // 2. Recent & Upcoming Bookings
        $bookings = Booking::where('workspace_id', $wid)
            ->with(['meetingType:id,name,duration_minutes,color,location_type', 'host:id,name,email', 'lead:id,name,email,phone'])
            ->orderBy('starts_at', 'desc')
            ->paginate(15);

        // 3. User Availability Rules
        $rules = AvailabilityRule::where('user_id', $user->id)
            ->orderBy('day_of_week')
            ->get();

        // 4. Overrides
        $overrides = AvailabilityOverride::where('user_id', $user->id)
            ->where('date', '>=', now()->toDateString())
            ->orderBy('date')
            ->get();

        // 5. Team Members
        $teamMembers = User::where('workspace_id', $wid)
            ->orWhere('client_id', $user->client_id)
            ->select('id', 'name', 'email', 'avatar')
            ->get();

        return Inertia::render('Booking/Index', [
            'meeting_types' => $meetingTypes,
            'bookings' => $bookings,
            'rules' => $rules,
            'overrides' => $overrides,
            'team_members' => $teamMembers,
            'user_timezone' => $user->timezone ?: 'UTC',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'location_type' => ['required', 'string', Rule::in([
                MeetingType::LOCATION_GOOGLE_MEET,
                MeetingType::LOCATION_PHONE,
                MeetingType::LOCATION_IN_PERSON,
                MeetingType::LOCATION_CUSTOM,
            ])],
            'location_details' => ['nullable', 'string', 'max:256'],
            'description' => ['nullable', 'string', 'max:1000'],
            'buffer_before' => ['nullable', 'integer', 'min:0', 'max:120'],
            'buffer_after' => ['nullable', 'integer', 'min:0', 'max:120'],
            'min_notice_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'booking_window_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'color' => ['nullable', 'string', 'max:16'],
            'user_id' => ['nullable', 'integer'],
        ]);

        MeetingType::create([
            'workspace_id' => $wid,
            'user_id' => $validated['user_id'] ?? $user->id,
            'name' => $validated['name'],
            'duration_minutes' => $validated['duration_minutes'],
            'location_type' => $validated['location_type'],
            'location_details' => $validated['location_details'] ?? null,
            'description' => $validated['description'] ?? null,
            'buffer_before' => $validated['buffer_before'] ?? 0,
            'buffer_after' => $validated['buffer_after'] ?? 0,
            'min_notice_hours' => $validated['min_notice_hours'] ?? 4,
            'booking_window_days' => $validated['booking_window_days'] ?? 14,
            'color' => $validated['color'] ?? '#6366f1',
            'status' => MeetingType::STATUS_ACTIVE,
        ]);

        return back()->with('success', __('booking.created_success'));
    }

    public function update(Request $request, MeetingType $meetingType): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        if ($meetingType->workspace_id !== $wid) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:128'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'location_type' => ['required', 'string', Rule::in([
                MeetingType::LOCATION_GOOGLE_MEET,
                MeetingType::LOCATION_PHONE,
                MeetingType::LOCATION_IN_PERSON,
                MeetingType::LOCATION_CUSTOM,
            ])],
            'location_details' => ['nullable', 'string', 'max:256'],
            'description' => ['nullable', 'string', 'max:1000'],
            'buffer_before' => ['nullable', 'integer', 'min:0', 'max:120'],
            'buffer_after' => ['nullable', 'integer', 'min:0', 'max:120'],
            'min_notice_hours' => ['nullable', 'integer', 'min:0', 'max:168'],
            'booking_window_days' => ['nullable', 'integer', 'min:1', 'max:90'],
            'color' => ['nullable', 'string', 'max:16'],
            'status' => ['required', 'string', Rule::in([
                MeetingType::STATUS_ACTIVE,
                MeetingType::STATUS_INACTIVE,
            ])],
            'user_id' => ['nullable', 'integer'],
        ]);

        $meetingType->update($validated);

        return back()->with('success', __('booking.updated_success'));
    }

    public function destroy(Request $request, MeetingType $meetingType): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        if ($meetingType->workspace_id !== $wid) {
            abort(403);
        }

        $meetingType->delete();

        return back()->with('success', __('booking.deleted_success'));
    }

    public function updateAvailability(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        $user = $request->user();

        $validated = $request->validate([
            'timezone' => ['required', 'string', 'timezone'],
            'rules' => ['present', 'array'],
            'rules.*.day_of_week' => ['required', 'integer', 'between:0,6'],
            'rules.*.start_time' => ['required', 'string'],
            'rules.*.end_time' => ['required', 'string'],
        ]);

        $user->update(['timezone' => $validated['timezone']]);

        // Replace availability rules
        AvailabilityRule::where('user_id', $user->id)->delete();

        foreach ($validated['rules'] as $ruleData) {
            AvailabilityRule::create([
                'workspace_id' => $wid,
                'user_id' => $user->id,
                'day_of_week' => (int) $ruleData['day_of_week'],
                'start_time' => $ruleData['start_time'],
                'end_time' => $ruleData['end_time'],
                'timezone' => $validated['timezone'],
            ]);
        }

        return back()->with('success', __('booking.availability_updated'));
    }

    public function cancelBooking(Request $request, Booking $booking): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        if ($booking->workspace_id !== $wid) {
            abort(403);
        }

        $reason = $request->input('reason', 'Cancelled by host');
        $this->bookingService->cancelBooking($booking, $reason);

        return back()->with('success', __('booking.booking_cancelled_success'));
    }
}
