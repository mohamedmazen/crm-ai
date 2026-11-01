<?php

namespace App\Modules\Sales\Services;

use App\Events\InvoicePaid;
use App\Events\InvoiceSent;
use App\Mail\InvoiceSentMail;
use App\Models\Workspace;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\InvoicePayment;
use App\Modules\Sales\Models\Quote;
use App\Modules\Sales\Services\Documents\DocumentNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Raises invoices (usually from an accepted quote) and tracks their payments.
 */
class InvoiceService
{
    /** Days a new invoice stays payable before it becomes overdue. */
    private const DEFAULT_DUE_DAYS = 14;

    /**
     * Raise the invoice for an accepted quote. Calling it twice returns the
     * invoice already raised instead of opening a second one.
     */
    public function fromQuote(Quote $quote, ?int $userId): Invoice
    {
        if ($quote->status !== Quote::STATUS_ACCEPTED) {
            throw ValidationException::withMessages([
                'quote' => __('Only accepted quotes can be converted to an invoice.'),
            ]);
        }

        return DB::transaction(function () use ($quote, $userId) {
            // Serialise concurrent conversions: without the row lock two requests
            // could both see no invoice and each raise one for the same quote.
            $locked = Quote::whereKey($quote->id)->lockForUpdate()->first();

            if (! $locked || $locked->status !== Quote::STATUS_ACCEPTED) {
                throw ValidationException::withMessages([
                    'quote' => __('Only accepted quotes can be converted to an invoice.'),
                ]);
            }

            $existing = Invoice::where('quote_id', $locked->id)->first();

            if ($existing) {
                return $existing;
            }

            return Invoice::create([
                'workspace_id' => $locked->workspace_id,
                'number' => DocumentNumber::next('INV', 'invoices', (int) $locked->workspace_id),
                'quote_id' => $locked->id,
                'lead_id' => $locked->lead_id,
                'company_id' => $locked->company_id,
                'status' => Invoice::STATUS_DRAFT,
                'subtotal' => $locked->subtotal,
                'tax_total' => $locked->tax_total,
                'total' => $locked->total,
                'currency' => $locked->currency,
                'due_date' => now()->addDays(self::DEFAULT_DUE_DAYS)->toDateString(),
                'notes' => $locked->notes,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $workspaceId, ?int $userId, array $data): Invoice
    {
        return DB::transaction(function () use ($workspaceId, $userId, $data) {
            return Invoice::create([
                'workspace_id' => $workspaceId,
                'number' => DocumentNumber::next('INV', 'invoices', $workspaceId),
                'lead_id' => $data['lead_id'] ?? null,
                'company_id' => $data['company_id'] ?? null,
                'status' => Invoice::STATUS_DRAFT,
                'subtotal' => round((float) ($data['subtotal'] ?? 0), 2),
                'tax_total' => round((float) ($data['tax_total'] ?? 0), 2),
                'total' => round((float) ($data['total'] ?? 0), 2),
                'currency' => $data['currency'] ?? Workspace::whereKey($workspaceId)->value('currency_code'),
                'due_date' => $data['due_date'] ?? now()->addDays(self::DEFAULT_DUE_DAYS)->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $userId,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Invoice $invoice, array $data): Invoice
    {
        if ($invoice->isPaid() || $invoice->status === Invoice::STATUS_CANCELLED) {
            throw ValidationException::withMessages([
                'invoice' => __('A :status invoice can no longer be edited.', ['status' => $invoice->status]),
            ]);
        }

        $invoice->update([
            'lead_id' => array_key_exists('lead_id', $data) ? $data['lead_id'] : $invoice->lead_id,
            'company_id' => array_key_exists('company_id', $data) ? $data['company_id'] : $invoice->company_id,
            'subtotal' => round((float) ($data['subtotal'] ?? $invoice->subtotal), 2),
            'tax_total' => round((float) ($data['tax_total'] ?? $invoice->tax_total), 2),
            'total' => round((float) ($data['total'] ?? $invoice->total), 2),
            'currency' => $data['currency'] ?? $invoice->currency,
            'due_date' => $data['due_date'] ?? $invoice->due_date,
            'notes' => $data['notes'] ?? $invoice->notes,
        ]);

        return $invoice;
    }

    public function send(Invoice $invoice): Invoice
    {
        $email = $invoice->lead?->email;

        if (blank($email)) {
            throw ValidationException::withMessages([
                'invoice' => __('The linked lead needs an email address before the invoice can be sent.'),
            ]);
        }

        $invoice->forceFill(['status' => Invoice::STATUS_SENT])->save();

        Mail::to($email)->queue(new InvoiceSentMail($invoice->loadMissing('lead')));

        InvoiceSent::dispatch(
            (int) $invoice->workspace_id,
            (int) $invoice->id,
            $invoice->lead_id !== null ? (int) $invoice->lead_id : null,
            (float) $invoice->total,
            $invoice->currency,
        );

        return $invoice;
    }

    public function markViewed(Invoice $invoice): Invoice
    {
        if ($invoice->status === Invoice::STATUS_SENT) {
            $invoice->forceFill(['status' => Invoice::STATUS_VIEWED])->save();
        }

        return $invoice;
    }

    /**
     * Record a payment. Reaching the invoice total marks it paid and fires the
     * event Phase 6 (AI collections) listens to.
     *
     * @param  array<string, mixed>  $payment
     */
    public function recordPayment(Invoice $invoice, array $payment): InvoicePayment
    {
        $amount = round((float) ($payment['amount'] ?? 0), 2);

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => __('Enter a payment amount greater than zero.'),
            ]);
        }

        return DB::transaction(function () use ($invoice, $payment, $amount) {
            // Lock the invoice so two concurrent payments cannot both pass the
            // balance check and overpay it.
            $locked = Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [Invoice::STATUS_PAID, Invoice::STATUS_CANCELLED], true)) {
                throw ValidationException::withMessages([
                    'amount' => __('This invoice is :status and cannot take payments.', ['status' => $locked->status]),
                ]);
            }

            $balanceDue = $locked->balanceDue();

            if ($amount > $balanceDue) {
                throw ValidationException::withMessages([
                    'amount' => __('The payment cannot be more than the balance due (:balance).', [
                        'balance' => number_format($balanceDue, 2),
                    ]),
                ]);
            }

            $record = $locked->payments()->create([
                'amount' => $amount,
                'method' => $payment['method'] ?? null,
                'reference' => $payment['reference'] ?? null,
                'notes' => $payment['notes'] ?? null,
                'paid_at' => $payment['paid_at'] ?? now(),
            ]);

            $locked->refresh();

            if ($locked->balanceDue() <= 0) {
                $locked->forceFill([
                    'status' => Invoice::STATUS_PAID,
                    'paid_at' => $locked->paid_at ?? $record->paid_at,
                ])->save();

                InvoicePaid::dispatch(
                    (int) $locked->workspace_id,
                    (int) $locked->id,
                    $locked->lead_id !== null ? (int) $locked->lead_id : null,
                    (float) $locked->total,
                    $locked->currency,
                );
            }

            return $record;
        });
    }

    /** Move open invoices whose due date passed to overdue. Returns the count. */
    public function syncOverdue(int $workspaceId): int
    {
        return Invoice::forWorkspace($workspaceId)
            ->syncOverdue()
            ->update(['status' => Invoice::STATUS_OVERDUE]);
    }

    public function pdf(Invoice $invoice): PdfDocument
    {
        $invoice->loadMissing(['quote.items', 'lead', 'company', 'payments']);

        return Pdf::loadView('pdf.sales-invoice', ['invoice' => $invoice])->setPaper('a4');
    }
}
