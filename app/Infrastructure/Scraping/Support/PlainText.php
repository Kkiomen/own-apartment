<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

/**
 * Portal descriptions arrive as HTML fragments with entities and erratic
 * whitespace. The domain stores plain text: the details view renders it as
 * text, and storing markup we never render is a liability, not a feature.
 */
final class PlainText
{
    public static function from(?string $html, int $maxLength = 2000): ?string
    {
        if ($html === null) {
            return null;
        }

        // <br> and </p> carry the only line structure worth keeping.
        $text = (string) preg_replace('#<br\s*/?>|</p>#i', "\n", $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Order matters: trimming around newlines must not itself match
        // newlines, or every paragraph break collapses into one line.
        $text = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = (string) preg_replace('/[ \t]*\n[ \t]*/', "\n", $text);
        $text = trim((string) preg_replace('/\n{3,}/', "\n\n", $text));

        if ($text === '') {
            return null;
        }

        return mb_strlen($text) > $maxLength
            ? rtrim(mb_substr($text, 0, $maxLength)).'…'
            : $text;
    }
}
