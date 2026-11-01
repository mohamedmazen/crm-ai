<?php

namespace App\Modules\AI\Support;

/**
 * Pulls a JSON object out of an LLM reply.
 *
 * Models wrap JSON in prose and markdown fences even when told not to, so a
 * bare json_decode() is not enough. Extraction is brace-counting rather than a
 * regex so nested objects and arrays survive intact.
 */
class AiJson
{
    /**
     * @return array<string, mixed>|null
     */
    public static function decode(?string $raw): ?array
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $text = trim($raw);

        // Strip a fenced block, keeping whatever was inside it.
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $text, $matches)) {
            $text = trim($matches[1]);
        }

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        $object = self::firstObject($text);

        if ($object === null) {
            return null;
        }

        $decoded = json_decode($object, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The first balanced {...} block in the text, ignoring braces inside strings.
     */
    private static function firstObject(string $text): ?string
    {
        $start = strpos($text, '{');

        if ($start === false) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $escaped = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }

                if ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }

        return null;
    }
}
