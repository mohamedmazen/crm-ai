<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\Quote;
use App\Modules\Sales\Services\QuoteService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Customer-facing quote page. The unguessable public token is the only
 * credential, so every lookup is by token and never by id.
 */
class QuotePublicController extends Controller
{
    public function __construct(private readonly QuoteService $quotes) {}

    public function show(string $token): Response
    {
        $quote = $this->find($token);

        $this->quotes->expireIfNeeded($quote);
        $this->quotes->markViewed($quote);

        $quote->refresh()->load(['items', 'lead:id,name,email,phone', 'company:id,name', 'workspace:id,name']);

        return Inertia::render('Quotes/Public', [
            'quote' => $quote,
            'pdfUrl' => route('quotes.public.pdf', $quote->public_token),
            'accepted' => $quote->status === Quote::STATUS_ACCEPTED,
        ]);
    }

    public function accept(string $token): RedirectResponse
    {
        $quote = $this->find($token);

        $this->quotes->accept($quote);

        return back()->with('success', __('Thank you! Your acceptance has been recorded.'));
    }

    public function reject(string $token): RedirectResponse
    {
        $quote = $this->find($token);

        $this->quotes->reject($quote);

        return back()->with('success', __('Thanks for letting us know.'));
    }

    public function pdf(string $token): HttpResponse
    {
        $quote = $this->find($token);

        return $this->quotes->pdf($quote)->download("{$quote->number}.pdf");
    }

    private function find(string $token): Quote
    {
        return Quote::where('public_token', $token)
            ->with(['items', 'lead', 'company'])
            ->firstOrFail();
    }
}
