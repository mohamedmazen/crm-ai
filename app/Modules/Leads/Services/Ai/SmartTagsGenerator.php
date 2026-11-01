<?php

namespace App\Modules\Leads\Services\Ai;

use App\Modules\AI\Services\AiFeatureRunner;
use App\Modules\Leads\Models\Lead;
use App\Modules\Leads\Models\LeadTag;
use App\Modules\Leads\Services\LeadTagService;

/**
 * Suggests short, reusable tags for one lead.
 *
 * The model only proposes names; nothing is written until the user accepts a
 * suggestion through {@see LeadTagService}. That
 * keeps the ledger (one metered run per click) honest and leaves the tag set
 * under human control, which matters because tags drive filters and segments.
 */
class SmartTagsGenerator
{
    /** Most names worth showing at once — more turns a suggestion into a chore. */
    private const MAX_SUGGESTIONS = 5;

    /** Recent follow-up notes carry most of the qualifying signal. */
    private const ACTIVITY_LIMIT = 8;

    /** How many existing workspace tags the model may reuse. */
    private const VOCABULARY_LIMIT = 40;

    public function __construct(private readonly AiFeatureRunner $ai) {}

    /**
     * @return array<int, string> Tag names, already free of duplicates and of tags the lead has
     */
    public function suggest(Lead $lead): array
    {
        $existing = $lead->tags()->pluck('name')->all();
        $vocabulary = LeadTag::where('workspace_id', $lead->workspace_id)
            ->orderBy('name')
            ->limit(self::VOCABULARY_LIMIT)
            ->pluck('name')
            ->all();

        $reply = $this->ai->json(
            (int) $lead->workspace_id,
            'smart_tags',
            [['role' => 'user', 'content' => $this->context($lead, $existing, $vocabulary)]],
            ['system' => $this->systemPrompt(), 'max_tokens' => 300],
        );

        return $this->clean($reply['tags'] ?? [], $existing);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You label CRM leads with short tags a sales team can filter on later.

Reply with JSON only, no prose and no markdown:
{"tags": ["...", "..."]}

Rules:
- 3 to 5 tags.
- Each tag is 1 to 3 words, at most 40 characters, lowercase, no punctuation at the ends.
- Describe durable, factual traits: industry, business type, buying intent, deal size, location, preferred channel.
- Reuse a tag from the existing tag list when it fits; do not invent synonyms of tags the lead already has.
- Never write a sales pitch, a sentence, an action item or a person's name.
PROMPT;
    }

    /**
     * @param  array<int, string>  $existingTags
     * @param  array<int, string>  $vocabulary
     */
    private function context(Lead $lead, array $existingTags, array $vocabulary): string
    {
        $lines = [
            'Lead data:',
            '- name: '.($lead->name ?: 'unknown'),
            '- category: '.($lead->category ?: 'unknown'),
            '- city: '.($lead->city ?: 'unknown'),
            '- country: '.($lead->country ?: 'unknown'),
            '- website: '.($lead->website ?: 'none'),
            '- source: '.($lead->source ?: 'unknown'),
            '- utm source: '.($lead->utm_source ?: 'none'),
            '- utm campaign: '.($lead->utm_campaign ?: 'none'),
            '- stage: '.($lead->stage?->name ?: 'unassigned'),
            '- status: '.($lead->status ?: 'new'),
            '- score: '.($lead->score ?? 0).' ('.($lead->score_band ?: 'unscored').')',
            '- deal value: '.($lead->deal_value !== null ? $lead->deal_value.' '.$lead->currency : 'unknown'),
            '- tags: '.($existingTags === [] ? 'none' : implode(', ', $existingTags)),
            '- workspace tag vocabulary: '.($vocabulary === [] ? 'none' : implode(', ', $vocabulary)),
        ];

        $activities = $lead->activities()->limit(self::ACTIVITY_LIMIT)->get();

        if ($activities->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Recent activity (newest first):';

            foreach ($activities as $activity) {
                $body = trim((string) $activity->body);
                $body = $body === '' ? '' : ' — '.mb_substr($body, 0, 160);
                $lines[] = '- '.$activity->type.': '.(string) data_get($activity->meta, 'outcome', 'none').$body;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Trusts nothing the model returns: unknown shapes, over-long names and
     * duplicates collapse into a short clean list.
     *
     * @param  array<int, string>  $existing
     * @return array<int, string>
     */
    private function clean(mixed $raw, array $existing): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $taken = array_map(fn (string $name) => mb_strtolower($name), $existing);
        $tags = [];

        foreach ($raw as $candidate) {
            if (! is_string($candidate)) {
                continue;
            }

            $name = trim(preg_replace('/\s+/u', ' ', $candidate) ?? '');
            $name = rtrim($name, ' .,;:!?-–—');

            if ($name === '' || mb_strlen($name) > 64) {
                continue;
            }

            $key = mb_strtolower($name);

            if (in_array($key, $taken, true)) {
                continue;
            }

            $taken[] = $key;
            $tags[] = $name;
        }

        return array_slice($tags, 0, self::MAX_SUGGESTIONS);
    }
}
