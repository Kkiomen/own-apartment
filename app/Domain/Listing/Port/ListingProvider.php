<?php

declare(strict_types=1);

namespace App\Domain\Listing\Port;

use App\Domain\Listing\ListingData;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Search\SearchCriteria;

/**
 * A source of listings — one implementation per portal.
 *
 * Defined in the core's vocabulary, not the portals': "give me the flats that
 * could match these criteria". How that becomes a URL, pagination, JSON blob or
 * HTML soup is entirely the adapter's problem.
 */
interface ListingProvider
{
    public function portal(): Portal;

    /**
     * Listings that plausibly match. Adapters may pre-filter using the portal's
     * own query parameters, but are not trusted to filter correctly — the
     * domain re-checks every result against the criteria.
     *
     * Returns a generator-friendly iterable so a slow, paginated scrape can be
     * processed as it arrives instead of buffering thousands of rows.
     *
     * @return iterable<ListingData>
     */
    public function fetch(SearchCriteria $criteria): iterable;
}
