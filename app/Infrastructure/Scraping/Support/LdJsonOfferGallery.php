<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

/**
 * The gallery as schema.org publishes it: `Product.image`.
 *
 * The portable reader — anything that publishes structured data for search
 * engines publishes its photos this way. Verified on OLX, where it yields 5 of
 * the 13 photos on the page (hence OlxOfferGallery preferring the markup and
 * keeping this as its floor).
 *
 * Used on its own for Gratka, Morizon and Nieruchomości-online, whose offer
 * pages we could not check against a live advert. If they publish no Product
 * block this returns nothing and the stored gallery is simply left alone —
 * the failure mode is "no improvement", never a wrong gallery.
 */
final readonly class LdJsonOfferGallery implements OfferGallery
{
    public function __construct(private LdJsonExtractor $extractor) {}

    public function imagesFrom(string $html): array
    {
        foreach ($this->extractor->blocks($html) as $block) {
            $images = $this->imagesIn($block);

            if ($images !== []) {
                return $images;
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $block
     * @return list<string>
     */
    private function imagesIn(array $block): array
    {
        $image = $block['image'] ?? null;

        // A single photo is published as a bare string rather than a list.
        if (is_string($image)) {
            return [$image];
        }

        if (! is_array($image)) {
            return [];
        }

        return array_values(array_filter($image, 'is_string'));
    }
}
