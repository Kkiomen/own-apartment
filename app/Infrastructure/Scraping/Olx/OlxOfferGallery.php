<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Olx;

use App\Infrastructure\Scraping\Support\LdJsonOfferGallery;
use App\Infrastructure\Scraping\Support\OfferGallery;

/**
 * OLX's offer page, which is the one place the whole gallery exists.
 *
 * The exception to this codebase's rule of never parsing markup: an OLX offer
 * page carries no state blob at all — no `__PRERENDERED_STATE__` (that is the
 * search app), no `__NEXT_DATA__`. Its only structured data is a schema.org
 * Product, and that lists 5 photos where the page shows 13.
 *
 * So the slides are read from the markup, anchored on `data-testid` — an
 * attribute that exists for OLX's own tests, which makes it the most stable
 * thing in a page whose styling is rewritten constantly. The ld+json remains
 * the floor: if the markup ever changes shape we fall back to 5 photos rather
 * than to the 3 a search page gives.
 */
final readonly class OlxOfferGallery implements OfferGallery
{
    /** Wraps each photo of the gallery, and nothing else on the page. */
    private const SLIDE = 'data-testid="ad-photo"';

    public function __construct(private LdJsonOfferGallery $structuredData) {}

    public function imagesFrom(string $html): array
    {
        $fromMarkup = $this->slides($html);
        $fromLdJson = $this->structuredData->imagesFrom($html);

        return count($fromMarkup) >= count($fromLdJson) ? $fromMarkup : $fromLdJson;
    }

    /**
     * Scanned by hand rather than with a regex, for the reason
     * EmbeddedJsonExtractor is: an offer page is ~478 kB and PCRE gives up on
     * input that size silently, which reads as "this advert has no photos".
     *
     * @return list<string>
     */
    private function slides(string $html): array
    {
        $images = [];
        $at = 0;

        while (($slide = strpos($html, self::SLIDE, $at)) !== false) {
            $at = $slide + strlen(self::SLIDE);

            $url = $this->firstImageUrlAfter($html, $at);

            // Bounded by the next slide, so a gallery with a photo missing its
            // src cannot swallow an image from further down the page.
            $nextSlide = strpos($html, self::SLIDE, $at);

            if ($url === null || ($nextSlide !== false && $url[1] > $nextSlide)) {
                continue;
            }

            $images[] = $url[0];
        }

        return array_values(array_unique($images));
    }

    /**
     * @return array{0: string, 1: int}|null the URL and where it was found
     */
    private function firstImageUrlAfter(string $html, int $from): ?array
    {
        $src = strpos($html, 'src="', $from);

        if ($src === false) {
            return null;
        }

        $start = $src + 5;
        $end = strpos($html, '"', $start);

        if ($end === false) {
            return null;
        }

        $url = substr($html, $start, $end - $start);

        return str_starts_with($url, 'https://') ? [$url, $src] : null;
    }
}
