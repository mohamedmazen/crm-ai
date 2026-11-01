<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Services\InvoiceService;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Customer-facing invoice page. Like the public quote page, the token is the
 * only credential; payments are recorded by the workspace, not the customer.
 */
class InvoicePublicController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    public function show(string $token): Response
    {
        $invoice = $this->find($token);

        $this->invoices->markViewed($invoice);

        $invoice->refresh()->load(['payments', 'lead:id,name,email,phone', 'company:id,name', 'quote.items', 'workspace:id,name']);

        return Inertia::render('Invoices/Public', [
            'invoice' => $invoice,
            'pdfUrl' => route('invoices.public.pdf', $invoice->public_token),
            'paidAmount' => $invoice->paidAmount(),
            'balanceDue' => $invoice->balanceDue(),
        ]);
    }

    public function pdf(string $token): HttpResponse
    {
        $invoice = $this->find($token);

        return $this->invoices->pdf($invoice)->download("{$invoice->number}.pdf");
    }

    private function find(string $token): Invoice
    {
        return Invoice::where('public_token', $token)
            ->with(['lead', 'company', 'quote.items', 'payments'])
            ->firstOrFail();
    }
}
