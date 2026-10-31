<?php

namespace App\Mail;

use App\Modules\Sales\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BookingConfirmedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Booking $booking,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Confirmed: :meeting on :date', [
                'meeting' => $this->booking->meetingType?->name ?? __('your meeting'),
                'date' => $this->booking->starts_at->toDayDateTimeString(),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.booking-confirmed');
    }
}
