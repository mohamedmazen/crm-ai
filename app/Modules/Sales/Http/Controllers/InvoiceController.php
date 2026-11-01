<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Modules\Leads\Models\Company;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Services\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class InvoiceController extends Controller
{
    public function __construct(private readonly InvoiceService $invoices) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        // Opening the list is the natural moment to age open invoices.
        $this->invoices->syncOverdue($wid);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Invoice::STATUSES)],
        ]);

        $invoices = Invoice::forWorkspace($wid)
            ->with(['lead:id,name,email', 'company:id,name', 'quote:id,number'])
            ->when(filled($filters['status'] ?? null), fn ($query) => $query->where('status', $filters['status']))
            ->when(filled($filters['q'] ?? null), function ($query) use ($filters) {
                $term = '%'.$filters['q'].'%';
                $query->where(function ($inner) use ($term) {
                    $inner->where('number', 'like', $term)
                        ->orWhereHas('lead', fn ($lead) => $lead->where('name', 'like', $term)->orWhere('email', 'like', $term))
                        ->orWhereHas('company', fn ($company) => $company->where('name', 'like', $term));
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return Inertia::render('Invoices/Index', [
            'invoices' => $invoices,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'stats' => [
                'total' => Invoice::forWorkspace($wid)->count(),
                'outstanding' => (float) Invoice::forWorkspace($wid)
                    ->whereIn('status', [Invoice::STATUS_SENT, Invoice::STATUS_VIEWED, Invoice::STATUS_OVERDUE])
                    ->sum('total'),
                'paid' => (float) Invoice::forWorkspace($wid)->where('status', Invoice::STATUS_PAID)->sum('total'),
                'overdue' => Invoice::forWorkspace($wid)->where('status', Invoice::STATUS_OVERDUE)->count(),
            ],
            'leads' => Lead::where('workspace_id', $wid)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'email', 'company_id']),
            'companies' => Company::where('workspace_id', $wid)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name']),
            'currencies' => $this->currencies(),
            'defaultCurrency' => Currency::defaultCode(),
        ]);
    }

    public function show(Request $request, Invoice $invoice): Response
    {
        abort_unless((int) $invoice->workspace_id === $this->workspaceId($request), 403);

        $invoice->load([
            'payments',
            'lead:id,name,email,phone',
            'company:id,name',
            'quote:id,number,uuid,public_token',
            'quote.items',
        ]);

        return Inertia::render('Invoices/Show', [
            'invoice' => $invoice,
            'publicUrl' => route('invoices.public.show', $invoice->public_token),
            'paidAmount' => $invoice->paidAmount(),
            'balanceDue' => $invoice->balanceDue(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $data = $this->validated($request, $wid);

        $invoice = $this->invoices->create($wid, (int) $request->user()->id, $data);

        return redirect()
            ->route('client.sales.invoices.show', $invoice->id)
            ->with('success', __('Invoice :number created.', ['number' => $invoice->number]));
    }

    public function update(Request $request, Invoice $invoice): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $invoice->workspace_id === $wid, 403);

        $this->invoices->update($invoice, $this->validated($request, $wid));

        return back()->with('success', __('Invoice updated.'));
    }

    public function destroy(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless((int) $invoice->workspace_id === $this->workspaceId($request), 403);

        if ($invoice->isPaid()) {
            throw ValidationException::withMessages([
                'invoice' => __('A paid invoice cannot be deleted.'),
            ]);
        }

        $invoice->delete();

        return redirect()
            ->route('client.sales.invoices.index')
            ->with('success', __('Invoice deleted.'));
    }

    public function send(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless((int) $invoice->workspace_id === $this->workspaceId($request), 403);

        $this->invoices->send($invoice);

        return back()->with('success', __('Invoice sent to the customer.'));
    }

    public function payment(Request $request, Invoice $invoice): RedirectResponse
    {
        abort_unless((int) $invoice->workspace_id === $this->workspaceId($request), 403);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999999'],
            'method' => ['nullable', 'string', 'max:32'],
            'reference' => ['nullable', 'string', 'max:256'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $this->invoices->recordPayment($invoice, $data);

        return back()->with('success', __('Payment recorded.'));
    }

    public function pdf(Request $request, Invoice $invoice): HttpResponse
    {
        abort_unless((int) $invoice->workspace_id === $this->workspaceId($request), 403);

        return $this->invoices->pdf($invoice)->download("{$invoice->number}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $workspaceId): array
    {
        $data = $request->validate([
            'lead_id' => [
                'nullable', 'integer',
                Rule::exists('leads', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'company_id' => [
                'nullable', 'integer',
                Rule::exists('companies', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'subtotal' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'tax_total' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'total' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'currency' => ['nullable', 'string', 'max:8'],
            'due_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $data['tax_total'] = round((float) ($data['tax_total'] ?? 0), 2);
        $data['total'] = round((float) ($data['total'] ?? ((float) $data['subtotal'] + $data['tax_total'])), 2);

        return $data;
    }

    /**
     * @return array<int, string>
     */
    private function currencies(): array
    {
        $codes = Currency::where('enabled', true)->orderBy('code')->pluck('code')->all();

        return $codes !== [] ? $codes : ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'EGP'];
    }
}
