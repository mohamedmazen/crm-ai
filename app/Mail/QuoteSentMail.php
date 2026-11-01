<?php

namespace App\Mail;

use App\Modules\Sales\Models\Quote;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class QuoteSentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Quote $quote,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Quote :number from :app', [
                'number' => $this->quote->number,
                'app' => config('app.name'),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.quote-sent');
    }
}
