<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Currency;
use App\Modules\Leads\Models\Company;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Product;
use App\Modules\Sales\Models\Quote;
use App\Modules\Sales\Services\QuoteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class QuoteController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    private function workspaceId(Request $request): int
    {
        return (int) ($request->user()->current_workspace_id ?? $request->user()->workspace_id);
    }

    public function index(Request $request): Response
    {
        $wid = $this->workspaceId($request);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Quote::STATUSES)],
        ]);

        $quotes = Quote::forWorkspace($wid)
            ->with(['lead:id,name,email', 'company:id,name', 'invoice:id,quote_id,number,status'])
            ->withCount('items')
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

        return Inertia::render('Quotes/Index', [
            'quotes' => $quotes,
            'filters' => [
                'q' => $filters['q'] ?? '',
                'status' => $filters['status'] ?? '',
            ],
            'stats' => [
                'total' => Quote::forWorkspace($wid)->count(),
                'open' => Quote::forWorkspace($wid)->whereIn('status', [Quote::STATUS_SENT, Quote::STATUS_VIEWED])->count(),
                'accepted' => Quote::forWorkspace($wid)->where('status', Quote::STATUS_ACCEPTED)->count(),
                'accepted_value' => (float) Quote::forWorkspace($wid)->where('status', Quote::STATUS_ACCEPTED)->sum('total'),
            ],
            'defaultCurrency' => Currency::defaultCode(),
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('Quotes/Form', [
            'quote' => null,
            ...$this->formResources($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $wid = $this->workspaceId($request);

        $quote = $this->quotes->create(
            $wid,
            (int) $request->user()->id,
            $this->validated($request, $wid),
        );

        return redirect()
            ->route('client.sales.quotes.show', $quote->id)
            ->with('success', __('Quote :number created.', ['number' => $quote->number]));
    }

    public function show(Request $request, Quote $quote): Response
    {
        abort_unless((int) $quote->workspace_id === $this->workspaceId($request), 403);

        $this->quotes->expireIfNeeded($quote);
        $quote->refresh()->load(['items.product:id,name', 'lead:id,name,email,phone', 'company:id,name', 'invoice:id,quote_id,number,status,total']);

        return Inertia::render('Quotes/Show', [
            'quote' => $quote,
            'publicUrl' => route('quotes.public.show', $quote->public_token),
        ]);
    }

    public function edit(Request $request, Quote $quote): Response
    {
        abort_unless((int) $quote->workspace_id === $this->workspaceId($request), 403);

        $quote->load(['items']);

        return Inertia::render('Quotes/Form', [
            'quote' => $quote,
            ...$this->formResources($request),
        ]);
    }

    public function update(Request $request, Quote $quote): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $quote->workspace_id === $wid, 403);

        $this->quotes->update($quote, $this->validated($request, $wid));

        return redirect()
            ->route('client.sales.quotes.show', $quote->id)
            ->with('success', __('Quote updated.'));
    }

    public function destroy(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless((int) $quote->workspace_id === $this->workspaceId($request), 403);

        $quote->delete();

        return redirect()
            ->route('client.sales.quotes.index')
            ->with('success', __('Quote deleted.'));
    }

    public function send(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless((int) $quote->workspace_id === $this->workspaceId($request), 403);

        $this->quotes->send($quote);

        return back()->with('success', __('Quote sent to the customer.'));
    }

    public function accept(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless((int) $quote->workspace_id === $this->workspaceId($request), 403);

        $this->quotes->accept($quote);

        return back()->with('success', __('Quote marked as accepted.'));
    }

    public function reject(Request $request, Quote $quote): RedirectResponse
    {
        abort_unless((int) $quote->workspace_id === $this->workspaceId($request), 403);

        $this->quotes->reject($quote);

        return back()->with('success', __('Quote marked as rejected.'));
    }

    public function convert(Request $request, Quote $quote): RedirectResponse
    {
        $wid = $this->workspaceId($request);
        abort_unless((int) $quote->workspace_id === $wid, 403);

        $invoice = $this->quotes->convertToInvoice($quote, (int) $request->user()->id);

        return redirect()
            ->route('client.sales.invoices.show', $invoice->id)
            ->with('success', __('Invoice :number raised from the quote.', ['number' => $invoice->number]));
    }

    public function pdf(Request $request, Quote $quote): HttpResponse
    {
        abort_unless((int) $quote->workspace_id === $this->workspaceId($request), 403);

        return $this->quotes->pdf($quote)->download("{$quote->number}.pdf");
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, int $workspaceId): array
    {
        return $request->validate([
            'lead_id' => [
                'nullable', 'integer',
                Rule::exists('leads', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'company_id' => [
                'nullable', 'integer',
                Rule::exists('companies', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'valid_until' => ['nullable', 'date'],
            'discount_type' => ['nullable', Rule::in([Quote::DISCOUNT_PERCENT, Quote::DISCOUNT_FIXED])],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:99999999999'],
            'currency' => ['nullable', 'string', 'max:8'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => [
                'nullable', 'integer',
                Rule::exists('products', 'id')->where(fn ($query) => $query->where('workspace_id', $workspaceId)),
            ],
            'items.*.description' => ['required', 'string', 'max:256'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'items.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formResources(Request $request): array
    {
        $wid = $this->workspaceId($request);

        $codes = Currency::where('enabled', true)->orderBy('code')->pluck('code')->all();

        return [
            'products' => Product::forWorkspace($wid)
                ->active()
                ->orderBy('name')
                ->get(['id', 'name', 'sku', 'price', 'tax_rate', 'unit', 'currency']),
            'leads' => Lead::where('workspace_id', $wid)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name', 'email', 'phone', 'company_id']),
            'companies' => Company::where('workspace_id', $wid)
                ->orderBy('name')
                ->limit(500)
                ->get(['id', 'name']),
            'currencies' => $codes !== [] ? $codes : ['USD', 'EUR', 'GBP', 'AED', 'SAR', 'EGP'],
            'defaultCurrency' => Currency::defaultCode(),
        ];
    }
}
