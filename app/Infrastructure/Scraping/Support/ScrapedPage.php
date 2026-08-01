<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use App\Domain\Listing\ListingData;

/**
 * One page of search results: what we could parse, and how many pages exist.
 *
 * `skipped` is not decoration. If a portal changes its markup, the honest
 * symptom is "parsed 0, skipped 44" — without that number the scan just looks
 * like a quiet day on the market.
 */
final readonly class ScrapedPage
{
    /**
     * @param  list<ListingData>  $listings
     */
    public function __construct(
        public array $listings,
        public int $totalPages,
        public int $skipped = 0,
    ) {}
}
