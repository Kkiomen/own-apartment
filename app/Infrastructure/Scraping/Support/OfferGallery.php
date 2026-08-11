<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

/**
 * Reads the full photo gallery out of one portal's offer page.
 *
 * Needed because every portal's *search* payload carries only the first three
 * photos of an advert — verified on OLX and Otodom, whose offer pages hold 13
 * and 20 for the same flats. The gallery is the one thing worth a second
 * request, and it costs nothing extra: the verification pass already opens
 * these pages.
 *
 * Per portal, not shared: OLX publishes the gallery as markup and Otodom as
 * JSON, and pretending otherwise would produce exactly the tangle of
 * `if ($this->portal === …)` this codebase avoids elsewhere.
 */
interface OfferGallery
{
    /**
     * @return list<string> photo URLs, empty when the page holds no gallery
     *                      this reader understands
     */
    public function imagesFrom(string $html): array;
}
