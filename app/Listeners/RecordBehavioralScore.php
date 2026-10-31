<?php

namespace App\Listeners;

use App\Events\CommerceEventReceived;
use App\Events\FormSubmitted;
use App\Events\MessageReceived;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\Scoring\BehavioralScorer;
use Illuminate\Support\Facades\Cache;

/**
 * Turns product events into behavioral scoring events (Phase 3.3).
 *
 * Kept separate from {@see AutomationTriggerListener} on purpose: automations
 * answer "what should happen", scoring answers "how good is this lead", and a
 * tenant's plan can enable one without the other.
 *
 * Each hook is idempotent — a retried webhook must not award the same reply or
 * order twice — using the same short-lived cache lock the automation trigger uses.
 */
class RecordBehavioralScore
{
    public function __construct(private readonly BehavioralScorer $scorer) {}

    public function handleMessageReceived(MessageReceived $event): void
    {
        $message = $event->message;

        // Only the lead's own inbound message counts; an outbound echo does not.
        if (($message->direction ?? 'in') !== 'in') {
            return;
        }

        $contactId = $message->conversation?->contact_id;
        if (! $contactId) {
            return;
        }

        if ($message->id && ! Cache::add("behavioral_score_lock:message:{$message->id}", 1, 60)) {
            return;
        }

        $body = $message->body ?? '';

        $this->scorer->recordForContact($contactId, 'message_reply', 'Replied to a message');

        // A pricing question is both a reply and its own, stronger signal.
        if (BehavioralScorer::isPriceInquiry($body)) {
            $this->scorer->recordForContact($contactId, 'price_inquiry', 'Asked about pricing');
        }
    }

    public function handleFormSubmitted(FormSubmitted $event): void
    {
        if (! Cache::add("behavioral_score_lock:form:{$event->submissionId}", 1, 60)) {
            return;
        }

        $lead = Lead::find($event->leadId);
        if (! $lead) {
            return;
        }

        $this->scorer->record($lead, 'form_submitted', 'Submitted the form "'.$event->formName.'"');
    }

    public function handleCommerceEvent(CommerceEventReceived $event): void
    {
        if ($event->eventType !== 'order.placed') {
            return;
        }

        $orderId = (string) ($event->context['order_id'] ?? $event->context['external_order_id'] ?? '');

        if (! Cache::add("behavioral_score_lock:order:{$event->contactId}:{$orderId}", 1, 60)) {
            return;
        }

        $this->scorer->recordForContact($event->contactId, 'order_placed', 'Placed an order');
    }
}
