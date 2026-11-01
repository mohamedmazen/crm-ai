<?php

namespace App\Modules\Leads\Services\Risk;

/**
 * How exposed a single lead is to going cold, with the evidence behind it.
 *
 * The reasons are the point: a salesperson who is told "high risk" has to look
 * at the card anyway, so the payload always carries the sentences that explain
 * the score and how many points each one contributed.
 */
final class LeadRisk
{
    public const LEVEL_NONE = 'none';

    public const LEVEL_LOW = 'low';

    public const LEVEL_MEDIUM = 'medium';

    public const LEVEL_HIGH = 'high';

    /**
     * @param  array<int, array{code: string, points: int, detail: string, days?: int}>  $reasons
     */
    public function __construct(
        public readonly string $level,
        public readonly int $score,
        public readonly array $reasons,
    ) {}

    public static function none(): self
    {
        return new self(self::LEVEL_NONE, 0, []);
    }

    public function isAtRisk(): bool
    {
        return $this->level !== self::LEVEL_NONE;
    }

    /** The strongest reason, for a badge tooltip. */
    public function headline(): ?string
    {
        $first = $this->reasons[0] ?? null;

        return $first['detail'] ?? null;
    }

    /**
     * @return array{level: string, score: int, headline: string|null, reasons: array<int, array{code: string, points: int, detail: string, days?: int}>}
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'score' => $this->score,
            'headline' => $this->headline(),
            'reasons' => $this->reasons,
        ];
    }
}
