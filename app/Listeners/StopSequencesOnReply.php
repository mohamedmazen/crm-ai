<?php

namespace App\Listeners;

use App\Events\MessageReceived;
use App\Events\SequenceReplied;
use App\Modules\Sales\Models\SequenceEvent;
use App\Modules\Sales\Services\SequenceEngine;
use App\Modules\Shared\Models\Contact;

/**
 * Stops active sequences when the lead replies, then broadcasts the reply as a
 * domain event (the seam AI SDR consumes in Phase 6).
 */
class StopSequencesOnReply
{
    public function __construct(private readonly SequenceEngine $engine) {}

    public function handle(MessageReceived $event): void
    {
        $message = $event->message;

        if ($message->direction !== 'in') {
            return;
        }

        $conversation = $message->conversation;

        if (! $conversation || ! $conversation->contact_id) {
            return;
        }

        $leadId = (int) (Contact::whereKey($conversation->contact_id)->value('lead_id') ?? 0);

        if ($leadId <= 0) {
            return;
        }

        $workspaceId = (int) $conversation->workspace_id;

        $stopped = $this->engine->stopForLead($workspaceId, $leadId, 'replied');

        foreach ($stopped as $enrollment) {
            SequenceEvent::create([
                'enrollment_id' => $enrollment->id,
                'step_position' => (int) $enrollment->current_step,
                'type' => SequenceEvent::TYPE_REPLY,
                'created_at' => now(),
            ]);

            SequenceReplied::dispatch(
                $workspaceId,
                (int) $enrollment->sequence_id,
                (int) $enrollment->id,
                $leadId,
                (int) $message->id,
                (string) $message->channel,
            );
        }
    }
}
