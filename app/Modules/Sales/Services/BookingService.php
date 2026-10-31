<?php

namespace App\Modules\Sales\Services;

use App\Mail\BookingConfirmedMail;
use App\Mail\BookingRescheduledMail;
use App\Models\User;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Sales\Models\Booking;
use App\Modules\Sales\Models\MeetingType;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class BookingService
{
    public function __construct(
        private readonly PipelineManager $pipelineManager,
    ) {}

    /**
     * Create and record a new booking.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function createBooking(MeetingType $meetingType, array $data): Booking
    {
        $host = $meetingType->user ?? User::where('workspace_id', $meetingType->workspace_id)->first();
        if (! $host) {
            throw ValidationException::withMessages([
                'starts_at' => __('No available host found for this meeting type.'),
            ]);
        }

        $startsAt = Carbon::parse($data['starts_at'])->utc();
        $endsAt = $startsAt->copy()->addMinutes($meetingType->duration_minutes);

        return DB::transaction(function () use ($meetingType, $host, $data, $startsAt, $endsAt) {
            // 1. Conflict checking (including buffer)
            $bufferedStart = $startsAt->copy()->subMinutes($meetingType->buffer_before);
            $bufferedEnd = $endsAt->copy()->addMinutes($meetingType->buffer_after);

            $collision = Booking::where('host_user_id', $host->id)
                ->where('status', Booking::STATUS_CONFIRMED)
                ->where(function ($q) use ($bufferedStart, $bufferedEnd) {
                    $q->where('starts_at', '<', $bufferedEnd)
                        ->where('ends_at', '>', $bufferedStart);
                })
                ->lockForUpdate()
                ->exists();

            if ($collision) {
                throw ValidationException::withMessages([
                    'starts_at' => __('This time slot is no longer available. Please select another time.'),
                ]);
            }

            // 2. Find or create Lead
            $lead = null;
            $email = ! empty($data['guest_email']) ? trim($data['guest_email']) : null;
            $phone = ! empty($data['guest_phone']) ? trim($data['guest_phone']) : null;

            if ($email || $phone) {
                $lead = Lead::where('workspace_id', $meetingType->workspace_id)
                    ->where(function ($q) use ($email, $phone) {
                        if ($email) {
                            $q->where('email', $email);
                        }
                        if ($phone) {
                            $q->orWhere('phone', $phone);
                        }
                    })
                    ->first();
            }

            if (! $lead) {
                $defaultPipe = $this->pipelineManager->defaultPipelineFor($meetingType->workspace_id);
                $firstStage = $this->pipelineManager->firstStage($meetingType->workspace_id, $defaultPipe->id);

                $lead = Lead::create([
                    'workspace_id' => $meetingType->workspace_id,
                    'pipeline_id' => $defaultPipe->id,
                    'stage_id' => $firstStage->id,
                    'name' => $data['guest_name'] ?? 'Guest Booking',
                    'email' => $email,
                    'phone' => $phone,
                    'source' => 'Booking: '.$meetingType->name,
                    'assigned_user_id' => $host->id,
                    'assigned_at' => now(),
                ]);
            }

            // 3. Create Booking
            $booking = Booking::create([
                'workspace_id' => $meetingType->workspace_id,
                'meeting_type_id' => $meetingType->id,
                'lead_id' => $lead->id,
                'host_user_id' => $host->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => Booking::STATUS_CONFIRMED,
                'guest_name' => $data['guest_name'] ?? null,
                'guest_email' => $email,
                'guest_phone' => $phone,
                'guest_timezone' => $data['guest_timezone'] ?? 'UTC',
                'answers' => $data['answers'] ?? null,
            ]);

            // 4. Record Lead Activity
            LeadActivity::create([
                'workspace_id' => $meetingType->workspace_id,
                'lead_id' => $lead->id,
                'user_id' => $host->id,
                'type' => 'meeting',
                'body' => __('Appointment booked: :name on :date', [
                    'name' => $meetingType->name,
                    'date' => $startsAt->toDayDateTimeString().' UTC',
                ]),
                'meta' => [
                    'booking_id' => $booking->id,
                    'booking_uuid' => $booking->uuid,
                    'meeting_type' => $meetingType->name,
                    'meet_link' => $booking->meet_link,
                ],
                'occurred_at' => now(),
            ]);

            // Confirm the booking with the guest: Meet link plus cancel and
            // reschedule links. Queued so the public request stays fast.
            if (! empty($booking->guest_email)) {
                Mail::to($booking->guest_email)->queue(
                    new BookingConfirmedMail($booking->load('meetingType', 'host'))
                );
            }

            return $booking;
        });
    }

    /**
     * Cancel an existing booking.
     */
    public function cancelBooking(Booking $booking, ?string $reason = null): bool
    {
        if ($booking->status === Booking::STATUS_CANCELLED) {
            return true;
        }

        $booking->update([
            'status' => Booking::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'cancel_reason' => $reason,
        ]);

        if ($booking->lead_id) {
            LeadActivity::create([
                'workspace_id' => $booking->workspace_id,
                'lead_id' => $booking->lead_id,
                'user_id' => $booking->host_user_id,
                'type' => 'note',
                'body' => __('Appointment cancelled: :name. Reason: :reason', [
                    'name' => $booking->meetingType->name ?? 'Meeting',
                    'reason' => $reason ?: __('No reason provided'),
                ]),
                'occurred_at' => now(),
            ]);
        }

        return true;
    }

    /**
     * Move a confirmed booking to a new time slot.
     *
     * Only confirmed, upcoming meetings can be moved. The old slot is freed
     * (the collision check ignores this booking itself) and a fresh reminder
     * is armed by clearing reminder_sent_at so the guest hears about the new
     * time.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function rescheduleBooking(Booking $booking, array $data): Booking
    {
        if ($booking->status !== Booking::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'starts_at' => __('Only confirmed bookings can be rescheduled.'),
            ]);
        }

        $meetingType = $booking->meetingType;

        if (! $meetingType) {
            throw ValidationException::withMessages([
                'starts_at' => __('This booking is no longer available.'),
            ]);
        }

        $startsAt = Carbon::parse($data['starts_at'])->utc();

        if ($startsAt->isPast()) {
            throw ValidationException::withMessages([
                'starts_at' => __('Please choose a time in the future.'),
            ]);
        }

        $endsAt = $startsAt->copy()->addMinutes($meetingType->duration_minutes);

        return DB::transaction(function () use ($booking, $meetingType, $startsAt, $endsAt) {
            $bufferedStart = $startsAt->copy()->subMinutes($meetingType->buffer_before);
            $bufferedEnd = $endsAt->copy()->addMinutes($meetingType->buffer_after);

            $collision = Booking::where('host_user_id', $booking->host_user_id)
                ->where('status', Booking::STATUS_CONFIRMED)
                ->where('id', '!=', $booking->id)
                ->where(function ($q) use ($bufferedStart, $bufferedEnd) {
                    $q->where('starts_at', '<', $bufferedEnd)
                        ->where('ends_at', '>', $bufferedStart);
                })
                ->lockForUpdate()
                ->exists();

            if ($collision) {
                throw ValidationException::withMessages([
                    'starts_at' => __('This time slot is no longer available. Please select another time.'),
                ]);
            }

            $booking->update([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reminder_sent_at' => null,
            ]);

            if ($booking->lead_id) {
                LeadActivity::create([
                    'workspace_id' => $booking->workspace_id,
                    'lead_id' => $booking->lead_id,
                    'user_id' => $booking->host_user_id,
                    'type' => 'meeting',
                    'body' => __('Appointment rescheduled: :name on :date', [
                        'name' => $meetingType->name,
                        'date' => $startsAt->toDayDateTimeString().' UTC',
                    ]),
                    'meta' => [
                        'booking_id' => $booking->id,
                        'booking_uuid' => $booking->uuid,
                        'meeting_type' => $meetingType->name,
                        'meet_link' => $booking->meet_link,
                    ],
                    'occurred_at' => now(),
                ]);
            }

            $booking = $booking->fresh(['meetingType', 'host']);

            if (! empty($booking->guest_email)) {
                Mail::to($booking->guest_email)->queue(new BookingRescheduledMail($booking));
            }

            return $booking;
        });
    }
}
