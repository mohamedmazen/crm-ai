<?php

namespace Tests\Feature\Sales;

use App\Events\InvoicePaid;
use App\Events\QuoteAccepted;
use App\Events\QuoteSent;
use App\Mail\QuoteSentMail;
use App\Models\Plan;
use App\Modules\Leads\Models\Lead;
use App\Modules\Sales\Models\Invoice;
use App\Modules\Sales\Models\Product;
use App\Modules\Sales\Models\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductQuoteInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array{0: mixed, 1: mixed, 2: mixed} */
    private function actorWithQuotes(bool $quotesFeature = true): array
    {
        ['user' => $user, 'client' => $client, 'workspace' => $workspace] = $this->createWorkspaceContext();

        $plan = Plan::factory()->create([
            'feature_flags' => ['quotes' => $quotesFeature],
        ]);

        $this->attachPlanToClient($client, $plan);

        return [$user, $workspace, $client];
    }

    private function lead($workspace, array $overrides = []): Lead
    {
        return Lead::factory()->create($overrides + [
            'workspace_id' => $workspace->id,
            'name' => 'Serenity Spa',
            'email' => 'owner@serenityspa.com',
            'phone' => '+15550002222',
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    private function quotePayload(Lead $lead, ?array $items = null): array
    {
        return [
            'lead_id' => $lead->id,
            'valid_until' => now()->addDays(10)->toDateString(),
            'discount_type' => null,
            'discount_value' => 0,
            'currency' => 'USD',
            'items' => $items ?? [
                ['description' => 'Consulting', 'quantity' => 2, 'unit_price' => 100, 'tax_rate' => 10],
                ['description' => 'Setup fee', 'quantity' => 1, 'unit_price' => 50, 'tax_rate' => 0],
            ],
        ];
    }

    private function createQuote($user, Lead $lead, ?array $items = null): Quote
    {
        $this->actingAs($user)
            ->post(route('client.sales.quotes.store'), $this->quotePayload($lead, $items))
            ->assertRedirect();

        return Quote::where('workspace_id', $lead->workspace_id)->latest('id')->firstOrFail();
    }

    /** Put a quote in the state a customer can act on. */
    private function sendQuote($user, Quote $quote): Quote
    {
        Mail::fake();

        $this->actingAs($user)
            ->post(route('client.sales.quotes.send', $quote->id))
            ->assertRedirect();

        return $quote->fresh();
    }

    #[Test]
    public function sales_documents_are_gated_by_the_plan(): void
    {
        [$user] = $this->actorWithQuotes(quotesFeature: false);

        $this->actingAs($user)
            ->get(route('client.sales.quotes.index'))
            ->assertRedirect(route('client.pricing'))
            ->assertSessionHas('upgrade_required', true);
    }

    #[Test]
    public function products_are_scoped_to_the_workspace(): void
    {
        [$user, $workspace] = $this->actorWithQuotes();
        [$otherUser, $otherWorkspace] = $this->actorWithQuotes();

        $this->actingAs($user)->post(route('client.sales.products.store'), [
            'name' => 'Starter plan',
            'sku' => 'STARTER',
            'price' => 99,
            'currency' => 'USD',
            'tax_rate' => 10,
            'unit' => 'month',
            'status' => Product::STATUS_ACTIVE,
        ])->assertRedirect()->assertSessionHas('success');

        $product = Product::where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('STARTER', $product->sku);

        $this->actingAs($otherUser)
            ->put(route('client.sales.products.update', $product->id), [
                'name' => 'Hijacked',
                'price' => 1,
                'status' => Product::STATUS_ACTIVE,
            ])
            ->assertForbidden();

        $this->assertSame('Starter plan', $product->fresh()->name);
        $this->assertSame(0, Product::where('workspace_id', $otherWorkspace->id)->count());
    }

    #[Test]
    public function quote_totals_include_line_tax_and_discounts(): void
    {
        [$user, $workspace] = $this->actorWithQuotes();
        $lead = $this->lead($workspace);

        $payload = $this->quotePayload($lead);
        $payload['discount_type'] = Quote::DISCOUNT_PERCENT;
        $payload['discount_value'] = 10;

        $this->actingAs($user)
            ->post(route('client.sales.quotes.store'), $payload)
            ->assertRedirect();

        $quote = Quote::where('workspace_id', $workspace->id)->firstOrFail();

        // 2 × 100 + 1 × 50 = 250; 10% tax on the first line = 20; a 10% discount
        // removes 25 and the tax that rides on it (20 × 0.9 = 18).
        $this->assertSame('250.00', $quote->subtotal);
        $this->assertSame('18.00', $quote->tax_total);
        $this->assertSame('243.00', $quote->total);
        $this->assertCount(2, $quote->items);
        $this->assertSame('QT-'.now()->year.'-0001', $quote->number);
    }

    #[Test]
    public function quote_numbers_are_sequential_per_workspace(): void
    {
        [$user, $workspace] = $this->actorWithQuotes();
        [$otherUser, $otherWorkspace] = $this->actorWithQuotes();
        $lead = $this->lead($workspace);
        $otherLead = $this->lead($otherWorkspace, ['email' => 'other@example.com']);

        $first = $this->createQuote($user, $lead);
        $second = $this->createQuote($user, $lead);
        $foreign = $this->createQuote($otherUser, $otherLead);

        $year = now()->year;

        $this->assertSame("QT-{$year}-0001", $first->number);
        $this->assertSame("QT-{$year}-0002", $second->number);
        $this->assertSame("QT-{$year}-0001", $foreign->number);
    }

    #[Test]
    public function sending_a_quote_emails_the_customer_and_fires_an_event(): void
    {
        [$user, $workspace] = $this->actorWithQuotes();
        $lead = $this->lead($workspace);
        $quote = $this->createQuote($user, $lead);

        Event::fake([QuoteSent::class]);
        Mail::fake();

        $this->actingAs($user)
            ->post(route('client.sales.quotes.send', $quote->id))
            ->assertRedirect()
            ->assertSessionHas('success');

        $quote->refresh();

        $this->assertSame(Quote::STATUS_SENT, $quote->status);
        $this->assertNotNull($quote->sent_at);
        Mail::assertQueued(QuoteSentMail::class, fn (QuoteSentMail $mail) => $mail->quote->id === $quote->id);
        Event::assertDispatched(QuoteSent::class, fn (QuoteSent $event) => $event->quoteId === $quote->id
            && $event->leadId === $lead->id);
    }

    #[Test]
    public function the_public_quote_page_records_views_and_acceptance(): void
    {
        [$user, $workspace] = $this->actorWithQuotes();
        $lead = $this->lead($workspace);
        $quote = $this->sendQuote($user, $this->createQuote($user, $lead));

        Event::fake([QuoteAccepted::class]);

        $this->get(route('quotes.public.show', $quote->public_token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Quotes/Public')
                ->where('quote.number', $quote->number));

        $this->assertSame(Quote::STATUS_VIEWED, $quote->fresh()->status);

        $this->post(route('quotes.public.accept', $quote->public_token))
            ->assertRedirect()
            ->assertSessionHas('success');

        $quote->refresh();

        $this->assertSame(Quote::STATUS_ACCEPTED, $quote->status);
        $this->assertNotNull($quote->accepted_at);
        Event::assertDispatched(QuoteAccepted::class, fn (QuoteAccepted $event) => $event->quoteId === $quote->id);
    }

    #[Test]
    public function an_expired_quote_cannot_be_accepted(): void
    {
        [$user, $workspace] = $this->actorWithQuotes();
        $lead = $this->lead($workspace);
        $quote = $this->createQuote($user, $lead);

        $quote->forceFill([
            'status' => Quote::STATUS_SENT,
            'valid_until' => now()->subDay()->toDateString(),
        ])->save();

        $this->post(route('quotes.public.accept', $quote->public_token))
            ->assertRedirect()
            ->assertSessionHasErrors('quote');

        $this->assertSame(Quote::STATUS_EXPIRED, $quote->fresh()->status);
    }

    #[Test]
    public function an_accepted_quote_converts_to_one_invoice(): void
    {
        [$user, $workspace] = $this->actorWithQuotes();
        $lead = $this->lead($workspace);
        $quote = $this->sendQuote($user, $this->createQuote($user, $lead));

        $this->actingAs($user)
            ->post(route('client.sales.quotes.accept', $quote->id))
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('client.sales.quotes.convert', $quote->id))
            ->assertRedirect();

        $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();

        $this->assertSame('INV-'.now()->year.'-0001', $invoice->number);
        $this->assertSame($quote->total, $invoice->total);
        $this->assertSame($quote->currency, $invoice->currency);
        $this->assertSame($lead->id, $invoice->lead_id);

        // Converting again returns the same invoice instead of opening a second.
        $this->actingAs($user)->post(route('client.sales.quotes.convert', $quote->id))->assertRedirect();
        $this->assertSame(1, Invoice::where('quote_id', $quote->id)->count());
    }

    #[Test]
    public function a_draft_quote_cannot_be_accepted(): void
    {
        [$user, $workspace] = $this->actorWithQuotes();
        $lead = $this->lead($workspace);
        $quote = $this->createQuote($user, $lead);

        $this->actingAs($user)
            ->post(route('client.sales.quotes.accept', $quote->id))
            ->assertSessionHasErrors('quote');

        $this->assertSame(Quote::STATUS_DRAFT, $quote->fresh()->status);
    }

    #[Test]
    public function payments_mark_an_invoice_paid_once_the_total_is_covered(): void
    {
        [$user, $workspace] = $this->actorWithQuotes();
        $lead = $this->lead($workspace);
        $quote = $this->sendQuote($user, $this->createQuote($user, $lead));

        $this->actingAs($user)->post(route('client.sales.quotes.accept', $quote->id));
        $this->actingAs($user)->post(route('client.sales.quotes.convert', $quote->id));

        $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();

        Event::fake([InvoicePaid::class]);

        $this->actingAs($user)
            ->post(route('client.sales.invoices.payments.store', $invoice->id), [
                'amount' => 100,
                'method' => 'bank_transfer',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $invoice->refresh();
        $this->assertNotSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertSame(170.0, $invoice->balanceDue());

        $this->actingAs($user)
            ->post(route('client.sales.invoices.payments.store', $invoice->id), [
                'amount' => 170,
                'method' => 'bank_transfer',
            ])
            ->assertRedirect();

        $invoice->refresh();

        $this->assertSame(Invoice::STATUS_PAID, $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame(0.0, $invoice->balanceDue());
        Event::assertDispatched(InvoicePaid::class, fn (InvoicePaid $event) => $event->invoiceId === $invoice->id);
    }

    #[Test]
    public function quote_and_invoice_pdfs_download(): void
    {
        [$user, $workspace] = $this->actorWithQuotes();
        $lead = $this->lead($workspace);
        $quote = $this->createQuote($user, $lead);

        $this->actingAs($user)
            ->get(route('client.sales.quotes.pdf', $quote->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get(route('quotes.public.pdf', $quote->public_token))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $quote->forceFill(['status' => Quote::STATUS_ACCEPTED, 'accepted_at' => now()])->save();
        $this->actingAs($user)->post(route('client.sales.quotes.convert', $quote->id));

        $invoice = Invoice::where('quote_id', $quote->id)->firstOrFail();

        $this->actingAs($user)
            ->get(route('client.sales.invoices.pdf', $invoice->id))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->get(route('invoices.public.pdf', $invoice->public_token))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    #[Test]
    public function another_workspaces_quote_is_not_reachable(): void
    {
        [$user] = $this->actorWithQuotes();
        [$otherUser, $otherWorkspace] = $this->actorWithQuotes();
        $foreignQuote = $this->createQuote($otherUser, $this->lead($otherWorkspace));

        $this->actingAs($user)
            ->get(route('client.sales.quotes.show', $foreignQuote->id))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('client.sales.quotes.edit', $foreignQuote->id))
            ->assertForbidden();
    }

    #[Test]
    public function a_public_token_that_does_not_exist_is_not_found(): void
    {
        $this->get(route('quotes.public.show', 'not-a-real-token'))->assertNotFound();
        $this->get(route('invoices.public.show', 'not-a-real-token'))->assertNotFound();
    }
}
