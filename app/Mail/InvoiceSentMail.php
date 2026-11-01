<?php

namespace App\Mail;

use App\Modules\Sales\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class InvoiceSentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Invoice $invoice,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('Invoice :number from :app', [
                'number' => $this->invoice->number,
                'app' => config('app.name'),
            ]),
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.invoice-sent');
    }
}
