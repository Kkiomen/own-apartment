<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Otodom;

use App\Infrastructure\Scraping\Support\EmbeddedJsonExtractor;
use App\Infrastructure\Scraping\Support\OfferGallery;

/**
 * Otodom's offer page keeps the whole gallery in the same `__NEXT_DATA__` blob
 * its search pages use, at `props.pageProps.ad.images` — 20 photos for a flat
 * whose search entry carried 3.
 *
 * Each entry comes in four sizes; `large` is what the modal wants, with
 * `medium` as the fallback, exactly as OtodomListingParser reads the search
 * payload.
 */
final readonly class OtodomOfferGallery implements OfferGallery
{
    public function __construct(private EmbeddedJsonExtractor $extractor) {}

    public function imagesFrom(string $html): array
    {
        $data = $this->extractor->fromScriptTag($html, '__NEXT_DATA__');

        if ($data === null) {
            return [];
        }

        $images = [];

        foreach ($this->imageList($data) as $image) {
            $url = is_array($image) ? ($image['large'] ?? $image['medium'] ?? null) : null;

            if (is_string($url)) {
                $images[] = $url;
            }
        }

        return $images;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    private function imageList(array $data): array
    {
        $node = $data;

        foreach (['props', 'pageProps', 'ad', 'images'] as $key) {
            if (! is_array($node) || ! array_key_exists($key, $node)) {
                return [];
            }

            $node = $node[$key];
        }

        return is_array($node) ? array_values($node) : [];
    }
}
