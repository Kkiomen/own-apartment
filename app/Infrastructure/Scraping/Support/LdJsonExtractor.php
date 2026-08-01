<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use JsonException;

/**
 * Pulls every `application/ld+json` block out of a page.
 *
 * Gratka, Morizon and Nieruchomości-online all publish their results as
 * schema.org structured data. That is a far better contract to depend on than
 * their Nuxt payloads: it exists so machines can read it, and it is what their
 * SEO depends on, so it does not churn.
 */
final class LdJsonExtractor
{
    /**
     * @return list<array<string, mixed>>
     */
    public function blocks(string $html): array
    {
        $found = preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#s', $html, $matches);

        if ($found === false || $found === 0) {
            return [];
        }

        $blocks = [];

        foreach ($matches[1] as $raw) {
            try {
                $decoded = json_decode(trim($raw), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }

            if (is_array($decoded)) {
                $blocks[] = $decoded;
            }
        }

        return $blocks;
    }

    /**
     * Walk a fixed key path into a decoded block.
     *
     * @param  array<string, mixed>  $block
     * @param  list<string|int>  $path
     * @return list<array<string, mixed>>|null
     */
    public function follow(array $block, array $path): ?array
    {
        $node = $block;

        foreach ($path as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return null;
            }

            $node = $node[$key];
        }

        if (! is_array($node)) {
            return null;
        }

        return array_values(array_filter($node, 'is_array'));
    }
}
