<?php

namespace App\Modules\Leads\Services\Ai;

use App\Modules\AI\Services\AiFeatureRunner;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Services\Risk\AtRiskDetector;
use App\Modules\Shared\Models\Conversation;
use App\Modules\Shared\Models\Message;

/**
 * The sales coach: what to do next with this lead, why, and a reply ready to
 * send — grounded in the conversation history the workspace already has.
 *
 * Nothing is persisted. Advice is a suggestion the rep reads and acts on, so a
 * stale recommendation can never reappear later as if it were a record.
 */
class LeadCoach
{
    /** Turns of conversation worth sending: enough for intent, cheap enough to repeat. */
    private const MESSAGE_LIMIT = 12;

    private const ACTIVITY_LIMIT = 8;

    private const MAX_ACTIVITY_CHARS = 160;

    private const MAX_MESSAGE_CHARS = 220;

    private const CHANNELS = ['email', 'whatsapp', 'call'];

    public function __construct(
        private readonly AiFeatureRunner $ai,
        private readonly AtRiskDetector $risk,
    ) {}

    /**
     * @return array{next_step: string, why: string, reply: string, channel: string|null, score_reason: string, at_risk: bool}|null
     *                                                                                                                              Null when the model did not return usable advice.
     */
    public function advise(Lead $lead): ?array
    {
        $risk = $this->risk->analyzeOne($lead);

        $reply = $this->ai->json(
            (int) $lead->workspace_id,
            'lead_coach',
            [['role' => 'user', 'content' => $this->context($lead, $risk->toArray())]],
            ['system' => $this->systemPrompt(), 'max_tokens' => 700],
        );

        if ($reply === null) {
            return null;
        }

        $nextStep = $this->text($reply['next_step'] ?? null, 240);

        if ($nextStep === '') {
            return null;
        }

        $channel = $this->text($reply['channel'] ?? null, 16);

        return [
            'next_step' => $nextStep,
            'why' => $this->text($reply['why'] ?? null, 400),
            'reply' => $this->text($reply['reply'] ?? null, 1200, multiline: true),
            'channel' => in_array($channel, self::CHANNELS, true) ? $channel : null,
            'score_reason' => $this->text($reply['score_reason'] ?? null, 400),
            'at_risk' => $risk->isAtRisk(),
        ];
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a sales coach inside a CRM. A rep has opened one lead and needs advice they can act on today.

Reply with JSON only, no prose and no markdown:
{"next_step": "...", "why": "...", "reply": "...", "channel": "email|whatsapp|call", "score_reason": "..."}

Rules:
- next_step: one concrete action, at most 20 words, phrased as an instruction.
- why: one sentence that cites the evidence you were given, never a generic sales tip.
- reply: a message the rep can send as-is, at most 120 words, in the same language as the conversation (English when there is none). Personalise with the first name only; no other placeholders.
- channel: the channel the evidence points at. Use "call" when the lead is quiet but valuable, "whatsapp" or "email" when there is a thread on that channel.
- score_reason: one sentence explaining the lead's current score in plain language.
- If the evidence is thin, say what is missing instead of inventing facts. Never mention data you were not given.
PROMPT;
    }

    /**
     * @param  array{level: string, score: int, headline: string|null, reasons: array<int, array{code: string, points: int, detail: string}>}  $risk
     */
    private function context(Lead $lead, array $risk): string
    {
        $lines = [
            'Lead:',
            '- name: '.($lead->name ?: 'unknown'),
            '- company: '.($lead->company?->name ?: 'unknown'),
            '- category: '.($lead->category ?: 'unknown'),
            '- city: '.($lead->city ?: 'unknown').', '.($lead->country ?: 'unknown'),
            '- source: '.($lead->source ?: 'unknown'),
            '- stage: '.($lead->stage?->name ?: 'unassigned'),
            '- status: '.($lead->status ?: 'new'),
            '- deal value: '.($lead->deal_value !== null ? $lead->deal_value.' '.$lead->currency : 'unknown'),
            '- score: '.($lead->score ?? 0).' ('.($lead->score_band ?: 'unscored').')',
            '- tags: '.($lead->tags->pluck('name')->implode(', ') ?: 'none'),
            '- at risk: '.($risk['level'] === 'none' ? 'no' : "yes ({$risk['score']}/100)"),
        ];

        foreach ($risk['reasons'] as $reason) {
            $lines[] = '  * '.$reason['detail'];
        }

        $breakdown = $lead->score_breakdown ?? [];

        if ($breakdown !== []) {
            $lines[] = '';
            $lines[] = 'Score breakdown:';

            foreach ($breakdown as $row) {
                $lines[] = sprintf(
                    '- %s: %s',
                    $row['detail'] ?? ($row['rule'] ?? 'rule'),
                    isset($row['max']) ? $row['points'].'/'.$row['max'] : $row['points'] ?? '?',
                );
            }
        }

        $activities = $lead->activities()->limit(self::ACTIVITY_LIMIT)->get();

        if ($activities->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Recent activity (newest first):';

            foreach ($activities as $activity) {
                $body = trim((string) $activity->body);
                $body = $body === '' ? '' : ': '.mb_substr($body, 0, self::MAX_ACTIVITY_CHARS);
                $lines[] = '- '.$activity->type.($body === '' ? '' : $body);
            }
        }

        $lines[] = '';
        $lines = [...$lines, ...$this->conversation($lead)];

        return implode("\n", $lines);
    }

    /**
     * The lead's newest thread, as a transcript the model can read. Empty when
     * the lead has never been pushed to contacts or never wrote.
     *
     * @return array<int, string>
     */
    private function conversation(Lead $lead): array
    {
        $contactId = $lead->contact?->id;

        if (! $contactId) {
            return ['Conversation: none (the lead has not been contacted yet).'];
        }

        $conversation = Conversation::where('workspace_id', $lead->workspace_id)
            ->where('contact_id', $contactId)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->first();

        if (! $conversation) {
            return ['Conversation: none (the lead has not been contacted yet).'];
        }

        $messages = Message::where('conversation_id', $conversation->id)
            ->whereNotNull('body')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(self::MESSAGE_LIMIT)
            ->get()
            ->reverse();

        if ($messages->isEmpty()) {
            return ['Conversation: a thread exists but has no text messages.'];
        }

        $lines = [
            'Conversation on '.($conversation->channelAccount?->channel ?? 'unknown').' channel (oldest first):',
        ];

        foreach ($messages as $message) {
            $who = $message->direction === 'in' ? 'lead' : 'rep';
            $body = trim(preg_replace('/\s+/u', ' ', (string) $message->body) ?? '');
            $lines[] = '- '.$who.': '.mb_substr($body, 0, self::MAX_MESSAGE_CHARS);
        }

        return $lines;
    }

    /** One trimmed, length-capped string, or '' when the model sent something odd. */
    private function text(mixed $value, int $max, bool $multiline = false): string
    {
        if (! is_string($value)) {
            return '';
        }

        // A ready-to-send message keeps its line breaks; everything else is one line.
        $clean = $multiline
            ? trim(preg_replace('/[ \t]+/u', ' ', $value) ?? '')
            : trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return mb_substr($clean, 0, $max);
    }
}
