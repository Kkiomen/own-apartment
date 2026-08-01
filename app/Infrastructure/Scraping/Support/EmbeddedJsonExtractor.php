<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use JsonException;

/**
 * Pulls a JSON payload out of a JavaScript string literal embedded in a page.
 *
 * Deliberately hand-written rather than a regex: portal search pages run to
 * several megabytes, and a pattern like `"(?:\\.|[^"\\])*"` blows PCRE's
 * backtrack limit on input that size. preg_match then returns false *silently*,
 * which reads as "the page has no data" — a scraper that quietly finds nothing
 * is far worse than one that crashes.
 */
final class EmbeddedJsonExtractor
{
    /**
     * @return array<string, mixed>|null null when the marker or payload is absent
     */
    public function extract(string $html, string $marker): ?array
    {
        $literal = $this->stringLiteralAfter($html, $marker);

        if ($literal === null) {
            return null;
        }

        try {
            // Two passes: the outer one unescapes the JS string literal, the
            // inner one decodes the JSON it contained.
            $inner = json_decode($literal, true, 512, JSON_THROW_ON_ERROR);

            if (! is_string($inner)) {
                return null;
            }

            $decoded = json_decode($inner, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Raw JSON held in a `<script id="...">` element — the Next.js convention
     * Otodom uses, as opposed to OLX's escaped JS string literal.
     *
     * @return array<string, mixed>|null
     */
    public function fromScriptTag(string $html, string $elementId): ?array
    {
        $marker = 'id="'.$elementId.'"';
        $at = strpos($html, $marker);

        if ($at === false) {
            return null;
        }

        $open = strpos($html, '>', $at);
        $close = $open === false ? false : strpos($html, '</script>', $open);

        if ($open === false || $close === false) {
            return null;
        }

        try {
            $decoded = json_decode(substr($html, $open + 1, $close - $open - 1), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    private function stringLiteralAfter(string $html, string $marker): ?string
    {
        $at = strpos($html, $marker);

        if ($at === false) {
            return null;
        }

        $opening = strpos($html, '"', $at + strlen($marker));

        if ($opening === false) {
            return null;
        }

        $length = strlen($html);

        for ($i = $opening + 1; $i < $length; $i++) {
            if ($html[$i] === '\\') {
                $i++;

                continue;
            }

            if ($html[$i] === '"') {
                return substr($html, $opening, $i - $opening + 1);
            }
        }

        return null;
    }
}
