<?php

namespace App\Modules\Sales\Services;

use App\Events\QuoteAccepted;
use App\Events\QuoteRejected;
use App\Events\QuoteSent;
use App\Mail\QuoteSentMail;
use App\Models\Workspace;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\Quote;
use App\Modules\Sales\Models\QuoteItem;
use App\Modules\Sales\Services\Documents\DocumentNumber;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Creates quotes, keeps their totals consistent with their line items, and
 * drives the send / view / accept / reject lifecycle.
 */
class QuoteService
{
    public function __construct(private readonly InvoiceService $invoices) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $workspaceId, ?int $userId, array $data): Quote
    {
        return DB::transaction(function () use ($workspaceId, $userId, $data) {
            $quote = Quote::create([
                'workspace_id' => $workspaceId,
                'number' => DocumentNumber::next('QT', 'quotes', $workspaceId),
                'lead_id' => $data['lead_id'] ?? null,
                'company_id' => $data['company_id'] ?? null,
                'status' => Quote::STATUS_DRAFT,
                'valid_until' => $data['valid_until'] ?? null,
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $data['discount_value'] ?? 0,
                'currency' => $data['currency'] ?? $this->defaultCurrency($workspaceId),
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
                'created_by' => $userId,
            ]);

            $this->syncItems($quote, $data['items'] ?? []);

            return $quote->fresh(['items', 'lead', 'company']);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Quote $quote, array $data): Quote
    {
        if ($quote->isClosed()) {
            throw ValidationException::withMessages([
                'quote' => __('This quote is :status and can no longer be edited.', ['status' => $quote->status]),
            ]);
        }

        return DB::transaction(function () use ($quote, $data) {
            $quote->update([
                'lead_id' => array_key_exists('lead_id', $data) ? $data['lead_id'] : $quote->lead_id,
                'company_id' => array_key_exists('company_id', $data) ? $data['company_id'] : $quote->company_id,
                'valid_until' => $data['valid_until'] ?? null,
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $data['discount_value'] ?? 0,
                'currency' => $data['currency'] ?? $quote->currency,
                'notes' => $data['notes'] ?? null,
                'terms' => $data['terms'] ?? null,
            ]);

            $this->syncItems($quote, $data['items'] ?? []);

            return $quote->fresh(['items', 'lead', 'company']);
        });
    }

    public function send(Quote $quote): Quote
    {
        $email = $quote->lead?->email;

        if (blank($email)) {
            throw ValidationException::withMessages([
                'quote' => __('The linked lead needs an email address before the quote can be sent.'),
            ]);
        }

        $quote->forceFill([
            'status' => Quote::STATUS_SENT,
            'sent_at' => now(),
        ])->save();

        Mail::to($email)->queue(new QuoteSentMail($quote->loadMissing('lead')));

        QuoteSent::dispatch(
            (int) $quote->workspace_id,
            (int) $quote->id,
            $quote->lead_id !== null ? (int) $quote->lead_id : null,
            (float) $quote->total,
            $quote->currency,
        );

        return $quote;
    }

    /** Record the customer opening the public page, once. */
    public function markViewed(Quote $quote): Quote
    {
        if (in_array($quote->status, [Quote::STATUS_SENT, Quote::STATUS_VIEWED], true)) {
            $quote->forceFill([
                'status' => Quote::STATUS_VIEWED,
                'viewed_at' => $quote->viewed_at ?? now(),
            ])->save();
        }

        return $quote;
    }

    public function accept(Quote $quote): Quote
    {
        if ($quote->status === Quote::STATUS_ACCEPTED) {
            return $quote;
        }

        $this->guardActionable($quote);

        $quote->forceFill([
            'status' => Quote::STATUS_ACCEPTED,
            'accepted_at' => now(),
        ])->save();

        QuoteAccepted::dispatch(
            (int) $quote->workspace_id,
            (int) $quote->id,
            $quote->lead_id !== null ? (int) $quote->lead_id : null,
            (float) $quote->total,
            $quote->currency,
        );

        return $quote;
    }

    public function reject(Quote $quote): Quote
    {
        if ($quote->status === Quote::STATUS_REJECTED) {
            return $quote;
        }

        $this->guardActionable($quote);

        $quote->forceFill([
            'status' => Quote::STATUS_REJECTED,
            'rejected_at' => now(),
        ])->save();

        QuoteRejected::dispatch(
            (int) $quote->workspace_id,
            (int) $quote->id,
            $quote->lead_id !== null ? (int) $quote->lead_id : null,
        );

        return $quote;
    }

    /** Turn a still-open quote whose validity date passed into an expired one. */
    public function expireIfNeeded(Quote $quote): Quote
    {
        if ($quote->isPastValidity()) {
            $quote->forceFill(['status' => Quote::STATUS_EXPIRED])->save();
        }

        return $quote;
    }

    /** Accept an accepted quote and raise its invoice (idempotent). */
    public function convertToInvoice(Quote $quote, ?int $userId): Invoice
    {
        return $this->invoices->fromQuote($quote, $userId);
    }

    public function pdf(Quote $quote): PdfDocument
    {
        $quote->loadMissing(['items', 'lead', 'company']);

        return Pdf::loadView('pdf.sales-quote', ['quote' => $quote])->setPaper('a4');
    }

    /**
     * Replace the quote's line items and recompute every money field from them.
     * Discounts are applied to the subtotal and reduce tax proportionally.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(Quote $quote, array $items): void
    {
        $quote->items()->delete();

        $subtotal = 0.0;
        $lineTax = 0.0;

        foreach (array_values($items) as $index => $item) {
            $quantity = round((float) ($item['quantity'] ?? 1), 2);
            $unitPrice = round((float) ($item['unit_price'] ?? 0), 2);
            $taxRate = round((float) ($item['tax_rate'] ?? 0), 2);
            $lineTotal = round($quantity * $unitPrice, 2);

            $subtotal += $lineTotal;
            $lineTax += round($lineTotal * ($taxRate / 100), 2);

            QuoteItem::create([
                'quote_id' => $quote->id,
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'],
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'tax_rate' => $taxRate,
                'total' => $lineTotal,
                'sort_order' => $index,
            ]);
        }

        $discountType = $quote->discount_type;
        $discountValue = round((float) $quote->discount_value, 2);
        $discount = match ($discountType) {
            Quote::DISCOUNT_PERCENT => round($subtotal * (min(100, max(0, $discountValue)) / 100), 2),
            Quote::DISCOUNT_FIXED => min($discountValue, $subtotal),
            default => 0.0,
        };

        $taxableRatio = $subtotal > 0 ? ($subtotal - $discount) / $subtotal : 0.0;

        $quote->forceFill([
            'subtotal' => round($subtotal, 2),
            'discount_value' => $discountValue,
            'tax_total' => round($lineTax * $taxableRatio, 2),
            'total' => round($subtotal - $discount + ($lineTax * $taxableRatio), 2),
        ])->save();
    }

    private function guardActionable(Quote $quote): void
    {
        $this->expireIfNeeded($quote);
        $quote->refresh();

        if ($quote->isClosed()) {
            throw ValidationException::withMessages([
                'quote' => __('This quote is :status and can no longer be changed.', ['status' => $quote->status]),
            ]);
        }

        if (! in_array($quote->status, [Quote::STATUS_SENT, Quote::STATUS_VIEWED], true)) {
            throw ValidationException::withMessages([
                'quote' => __('Send the quote before it can be accepted or rejected.'),
            ]);
        }
    }

    private function defaultCurrency(int $workspaceId): ?string
    {
        return Workspace::whereKey($workspaceId)->value('currency_code');
    }
}
