<?php

namespace App\Modules\Sales\Services;

use App\Modules\Ecommerce\Models\EcommerceCart;
use App\Modules\Ecommerce\Models\EcommerceOrder;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadActivity;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Sales\Models\LeadSource;
use App\Modules\Shared\Models\Contact;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class LeadAutoCaptureService
{
    public function __construct(
        private readonly PipelineManager $pipelineManager,
    ) {}

    /**
     * Auto-capture or link lead from an inbound WhatsApp conversation.
     */
    public function captureFromWhatsapp(
        int $workspaceId,
        Contact $contact,
        string $fromPhone,
        ?string $messageBody = null,
        ?int $conversationId = null
    ): Lead {
        $formattedPhone = str_starts_with($fromPhone, '+') ? $fromPhone : '+'.$fromPhone;

        // Check if contact already has a valid lead
        if ($contact->lead_id) {
            $existing = Lead::where('id', $contact->lead_id)->where('workspace_id', $workspaceId)->first();
            if ($existing) {
                return $existing;
            }
        }

        // Check if a lead with this phone already exists in the workspace
        $lead = Lead::where('workspace_id', $workspaceId)
            ->where(function ($q) use ($formattedPhone, $fromPhone) {
                $q->where('phone', $formattedPhone)
                    ->orWhere('phone', $fromPhone);
            })
            ->first();

        if ($lead) {
            $contact->update(['lead_id' => $lead->id]);

            return $lead;
        }

        // Find or create WhatsApp Lead Source
        $source = LeadSource::firstOrCreate(
            ['workspace_id' => $workspaceId, 'type' => LeadSource::TYPE_WHATSAPP],
            ['name' => 'WhatsApp Chat', 'status' => LeadSource::STATUS_ACTIVE]
        );
        $source->update(['last_lead_at' => now()]);

        // Pipeline and stage
        $pipeline = $this->pipelineManager->defaultPipelineFor($workspaceId);
        $stage = $this->pipelineManager->firstStage($workspaceId, $pipeline->id);

        $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));
        if ($name === '') {
            $name = 'WhatsApp '.$formattedPhone;
        }

        $lead = Lead::create([
            'workspace_id' => $workspaceId,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'lead_source_id' => $source->id,
            'source' => 'whatsapp',
            'name' => $name,
            'phone' => $formattedPhone,
            'status' => 'new',
        ]);

        $contact->update(['lead_id' => $lead->id]);

        if ($messageBody) {
            LeadActivity::create([
                'workspace_id' => $workspaceId,
                'lead_id' => $lead->id,
                'type' => 'note',
                'body' => 'Auto-captured from inbound WhatsApp message: '.Str::limit($messageBody, 120),
                'meta' => [
                    'source' => 'whatsapp',
                    'conversation_id' => $conversationId,
                ],
                'occurred_at' => now(),
            ]);
        }

        return $lead;
    }

    /**
     * Convert an existing contact into a lead.
     *
     * This is the one-click path used from the inbox: the contact already holds
     * the identity, so the lead is seeded from it rather than from a phone
     * number. If the contact is already linked, or a lead already matches its
     * phone/email, that lead is linked instead of creating a duplicate.
     */
    public function captureFromContact(int $workspaceId, Contact $contact, ?int $conversationId = null): Lead
    {
        $existing = $this->existingLeadFor($workspaceId, $contact);

        if ($existing) {
            return $existing;
        }

        $matching = $this->leadMatchingContact($workspaceId, $contact);

        if ($matching) {
            $contact->update(['lead_id' => $matching->id]);
            $this->applyCommerceDealValue($matching, $contact);

            return $matching;
        }

        $source = LeadSource::firstOrCreate(
            ['workspace_id' => $workspaceId, 'type' => LeadSource::TYPE_MANUAL],
            ['name' => 'Manual', 'status' => LeadSource::STATUS_ACTIVE]
        );
        $source->update(['last_lead_at' => now()]);

        $pipeline = $this->pipelineManager->defaultPipelineFor($workspaceId);
        $stage = $this->pipelineManager->firstStage($workspaceId, $pipeline->id);

        $lead = Lead::create([
            'workspace_id' => $workspaceId,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'lead_source_id' => $source->id,
            'source' => 'manual',
            'name' => $this->displayNameFor($contact),
            'email' => $contact->email,
            'phone' => $contact->phone_e164,
            'status' => 'new',
        ]);

        $contact->update(['lead_id' => $lead->id]);

        $this->applyCommerceDealValue($lead, $contact);

        LeadActivity::create([
            'workspace_id' => $workspaceId,
            'lead_id' => $lead->id,
            'type' => 'note',
            'body' => 'Converted from an inbox conversation.',
            'meta' => [
                'source' => 'inbox',
                'conversation_id' => $conversationId,
            ],
            'occurred_at' => now(),
        ]);

        return $lead;
    }

    /** The lead already attached to the contact, when it is still in this workspace. */
    private function existingLeadFor(int $workspaceId, Contact $contact): ?Lead
    {
        if (! $contact->lead_id) {
            return null;
        }

        return Lead::where('id', $contact->lead_id)
            ->where('workspace_id', $workspaceId)
            ->first();
    }

    /** An unlinked lead whose phone or email matches the contact. */
    private function leadMatchingContact(int $workspaceId, Contact $contact): ?Lead
    {
        if (! $contact->phone_e164 && ! $contact->email) {
            return null;
        }

        return Lead::where('workspace_id', $workspaceId)
            ->where(function ($query) use ($contact): void {
                if ($contact->phone_e164) {
                    $query->where('phone', $contact->phone_e164)
                        ->orWhere('phone', ltrim($contact->phone_e164, '+'));
                }

                if ($contact->email) {
                    $query->{$contact->phone_e164 ? 'orWhere' : 'where'}('email', $contact->email);
                }
            })
            ->first();
    }

    private function displayNameFor(Contact $contact): string
    {
        $name = trim(($contact->first_name ?? '').' '.($contact->last_name ?? ''));

        if ($name !== '') {
            return $name;
        }

        return $contact->phone_e164 ?? $contact->email ?? 'New lead';
    }

    /**
     * Seed the deal value from the contact's commerce history, so a converted
     * lead arrives on the board already carrying what the customer is worth.
     *
     * A contact with real orders is worth their lifetime order total; a contact
     * who only ever filled a cart is worth the cart. Orders win so an abandoned
     * cart behind a completed order does not double count.
     */
    private function applyCommerceDealValue(Lead $lead, Contact $contact): void
    {
        $commerce = $this->commerceValueFor($contact);

        if ($commerce['value'] <= 0) {
            return;
        }

        $lead->update([
            'deal_value' => $commerce['value'],
            'currency' => $commerce['currency'] ?? $lead->currency ?? 'USD',
        ]);
    }

    /**
     * @return array{value: float, currency: ?string}
     */
    private function commerceValueFor(Contact $contact): array
    {
        if (Schema::hasTable('ecommerce_orders')) {
            $orders = EcommerceOrder::where('contact_id', $contact->id)
                ->where(fn ($query) => $query
                    ->whereNull('status')
                    ->orWhereNotIn('status', ['cancelled', 'refunded']))
                ->get(['total', 'currency']);

            if ($orders->isNotEmpty()) {
                return [
                    'value' => (float) $orders->sum(fn (EcommerceOrder $order): float => (float) $order->total),
                    'currency' => $orders->first()->currency,
                ];
            }
        }

        if (Schema::hasTable('ecommerce_carts')) {
            $carts = EcommerceCart::where('contact_id', $contact->id)->get(['total', 'currency']);

            if ($carts->isNotEmpty()) {
                return [
                    'value' => (float) $carts->sum(fn (EcommerceCart $cart): float => (float) $cart->total),
                    'currency' => $carts->first()->currency,
                ];
            }
        }

        return ['value' => 0.0, 'currency' => null];
    }

    /**
     * Auto-capture lead from Meta Lead Ads webhook.
     *
     * @param  array<string, mixed>  $data
     */
    public function captureFromMetaLead(int $workspaceId, array $data, ?LeadSource $source = null): Lead
    {
        if (! $source) {
            $source = LeadSource::firstOrCreate(
                ['workspace_id' => $workspaceId, 'type' => LeadSource::TYPE_META_ADS],
                ['name' => 'Meta Lead Ads', 'status' => LeadSource::STATUS_ACTIVE]
            );
        }
        $source->update(['last_lead_at' => now()]);

        $pipeline = $this->pipelineManager->defaultPipelineFor($workspaceId);
        $stage = $this->pipelineManager->firstStage($workspaceId, $pipeline->id);

        $name = $data['full_name'] ?? $data['name'] ?? null;
        if (! $name && (! empty($data['first_name']) || ! empty($data['last_name']))) {
            $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
        }
        if (! $name) {
            $name = ! empty($data['email']) ? Str::headline(Str::before($data['email'], '@')) : 'Meta Lead #'.rand(1000, 9999);
        }

        $lead = Lead::create([
            'workspace_id' => $workspaceId,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'lead_source_id' => $source->id,
            'source' => 'Meta Lead Ads',
            'name' => $name,
            'email' => $data['email'] ?? null,
            'phone' => $data['phone_number'] ?? $data['phone'] ?? null,
            'utm_source' => $data['utm_source'] ?? 'facebook',
            'utm_medium' => $data['utm_medium'] ?? 'paid_social',
            'utm_campaign' => $data['campaign_name'] ?? $data['ad_name'] ?? $data['ad_id'] ?? null,
            'utm_term' => $data['adset_name'] ?? null,
            'utm_content' => $data['ad_id'] ?? null,
            'status' => 'new',
        ]);

        LeadActivity::create([
            'workspace_id' => $workspaceId,
            'lead_id' => $lead->id,
            'type' => 'note',
            'body' => 'Captured from Meta Lead Ad (Form ID: '.($data['form_id'] ?? 'N/A').')',
            'meta' => [
                'source' => 'meta_ads',
                'leadgen_id' => $data['leadgen_id'] ?? null,
                'form_id' => $data['form_id'] ?? null,
                'ad_id' => $data['ad_id'] ?? null,
            ],
            'occurred_at' => now(),
        ]);

        return $lead;
    }

    /**
     * Auto-capture lead from parsed email inquiry (IMAP).
     *
     * @param  array<string, mixed>  $emailData
     */
    public function captureFromEmail(int $workspaceId, array $emailData, ?LeadSource $source = null): Lead
    {
        if (! $source) {
            $source = LeadSource::firstOrCreate(
                ['workspace_id' => $workspaceId, 'type' => LeadSource::TYPE_IMAP],
                ['name' => 'Email Ingestion (IMAP)', 'status' => LeadSource::STATUS_ACTIVE]
            );
        }
        $source->update(['last_lead_at' => now()]);

        $pipeline = $this->pipelineManager->defaultPipelineFor($workspaceId);
        $stage = $this->pipelineManager->firstStage($workspaceId, $pipeline->id);

        $name = $emailData['name'] ?? null;
        if (! $name && ! empty($emailData['from_email'])) {
            $name = Str::headline(Str::before($emailData['from_email'], '@'));
        }

        $lead = Lead::create([
            'workspace_id' => $workspaceId,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'lead_source_id' => $source->id,
            'source' => 'email_imap',
            'name' => $name ?: 'Email Lead',
            'email' => $emailData['from_email'] ?? $emailData['email'] ?? null,
            'phone' => $emailData['phone'] ?? null,
            'status' => 'new',
        ]);

        LeadActivity::create([
            'workspace_id' => $workspaceId,
            'lead_id' => $lead->id,
            'type' => 'note',
            'body' => 'Email inquiry: '.($emailData['subject'] ?? 'New message')."\n".Str::limit($emailData['body'] ?? '', 200),
            'meta' => [
                'source' => 'imap',
                'subject' => $emailData['subject'] ?? null,
                'message_id' => $emailData['message_id'] ?? null,
            ],
            'occurred_at' => now(),
        ]);

        return $lead;
    }
}
