<?php

namespace App\Modules\Sales\Jobs;

use App\Mail\BookingReminderMail;
use App\Modules\Sales\Models\Booking;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

class SendBookingReminderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Booking $booking,
    ) {}

    public function handle(): void
    {
        // Re-read the booking: it may have been cancelled or already reminded
        // between the sweep that queued this job and the worker picking it up.
        $booking = $this->booking->fresh();

        if (! $booking
            || $booking->status !== Booking::STATUS_CONFIRMED
            || $booking->reminder_sent_at !== null
            || $booking->starts_at->isPast()
            || empty($booking->guest_email)) {
            return;
        }

        Mail::to($booking->guest_email)->queue(new BookingReminderMail($booking));

        $booking->update(['reminder_sent_at' => now()]);
    }
}
