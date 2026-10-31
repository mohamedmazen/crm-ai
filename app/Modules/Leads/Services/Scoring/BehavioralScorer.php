<?php

namespace App\Modules\Leads\Services\Scoring;

use App\Models\Workspace;
use App\Modules\Leads\Jobs\DecayLeadScoresJob;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadScoreEvent;
use App\Modules\Leads\Services\PipelineManager;
use App\Modules\Shared\Models\Contact;
use App\Services\FeatureGate;

/**
 * Behavioral lead scoring (Phase 3.3).
 *
 * Where {@see RuleBasedLeadScorer} reads the static signals the scraper already
 * collected, this reads what the lead has actually *done* — replied, opened a
 * campaign, asked about price, booked, ordered. Points are written as immutable
 * event rows so the total is always auditable and decay can be idempotent.
 *
 * Gated on the `sales_reports` feature: with the gate off nothing is recorded
 * and {@see summary()} returns zero, so a workspace scores exactly as it did
 * before this class existed.
 */
class BehavioralScorer
{
    /**
     * Event => points. Events with a negative weight are decay, written by
     * {@see DecayLeadScoresJob}.
     *
     * @var array<string, int>
     */
    public const EVENTS = [
        'message_reply' => 10,
        'campaign_open' => 3,
        'campaign_click' => 5,
        'price_inquiry' => 15,
        'booking_created' => 20,
        'form_submitted' => 10,
        'order_placed' => 25,
        'inactivity_7d' => -5,
        'inactivity_30d' => -15,
    ];

    /** Phrases marking an inbound message as a pricing question — English and Arabic. */
    public const PRICE_KEYWORDS = [
        'price', 'pricing', 'cost', 'how much', 'quote',
        'سعر', 'بكام', 'التكلفة', 'تكلفة', 'عرض سعر',
    ];

    /**
     * Workspace id => gate state. Mirrors the scorer's config cache: a board
     * rescore of thousands of leads resolves the plan once per workspace.
     *
     * @var array<int, bool>
     */
    private array $featureCache = [];

    /**
     * Whether behavioral scoring is switched on for this lead's workspace.
     */
    public function enabledFor(Lead $lead): bool
    {
        $workspaceId = (int) $lead->workspace_id;

        return $this->featureCache[$workspaceId] ??= FeatureGate::forWorkspace(
            Workspace::find($workspaceId)
        )->enabled('sales_reports');
    }

    /**
     * The lead's behavioral total, in one aggregate query.
     *
     * @return array{count: int, net: int, earned: int, spent: int, detail: string}
     */
    public function summary(Lead $lead): array
    {
        if (! $this->enabledFor($lead)) {
            return ['count' => 0, 'net' => 0, 'earned' => 0, 'spent' => 0, 'detail' => ''];
        }

        $row = LeadScoreEvent::where('lead_id', $lead->id)
            ->selectRaw('COUNT(*) as events')
            ->selectRaw('COALESCE(SUM(points), 0) as net')
            ->selectRaw('COALESCE(SUM(CASE WHEN points > 0 THEN points ELSE 0 END), 0) as earned')
            ->first();

        $count = (int) ($row->events ?? 0);
        $net = (int) ($row->net ?? 0);
        $earned = (int) ($row->earned ?? 0);

        return [
            'count' => $count,
            'net' => $net,
            'earned' => $earned,
            'spent' => $earned - $net,
            'detail' => $this->detail($count, $net),
        ];
    }

    /**
     * Record one behavioral event against a lead and rescore it immediately.
     *
     * Returns null when the event is unknown or behavioral scoring is gated off,
     * so every caller can fire blindly without knowing the tenant's plan.
     */
    public function record(Lead $lead, string $event, ?string $reason = null): ?LeadScoreEvent
    {
        if (! isset(self::EVENTS[$event]) || ! $this->enabledFor($lead)) {
            return null;
        }

        $points = self::EVENTS[$event];

        $entry = LeadScoreEvent::create([
            'lead_id' => $lead->id,
            'event' => $event,
            'points' => $points,
            'reason' => $reason,
        ]);

        // Engagement earns points and pushes the inactivity clock back; decay
        // only subtracts and leaves last_activity_at alone.
        if ($points > 0) {
            $lead->update(['last_activity_at' => now()]);
        }

        // Resolved lazily: PipelineManager depends on the LeadScorer contract,
        // which in turn depends on this class — injecting it here would cycle.
        app(PipelineManager::class)->rescore($lead);

        return $entry;
    }

    /**
     * Record an event against whichever lead a contact is linked to. Used by the
     * campaign tracking hooks, which only ever know the recipient's contact.
     */
    public function recordForContact(int $contactId, string $event, ?string $reason = null): ?LeadScoreEvent
    {
        $leadId = Contact::whereKey($contactId)->value('lead_id');

        if (! $leadId) {
            return null;
        }

        $lead = Lead::find($leadId);

        return $lead ? $this->record($lead, $event, $reason) : null;
    }

    /** True when a message body reads as a question about price. */
    public static function isPriceInquiry(?string $body): bool
    {
        if (! $body) {
            return false;
        }

        $haystack = mb_strtolower($body);

        foreach (self::PRICE_KEYWORDS as $keyword) {
            if (str_contains($haystack, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function detail(int $count, int $net): string
    {
        $sign = $net >= 0 ? '+' : '';

        return sprintf(
            'Behavioral engagement: %d event%s (%s%d points)',
            $count,
            $count === 1 ? '' : 's',
            $sign,
            $net,
        );
    }
}
