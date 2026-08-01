<?php

declare(strict_types=1);

namespace App\Domain\Listing\Port;

use App\Domain\Listing\Read\ListingFilter;
use App\Domain\Listing\Read\ListingSummaryPage;

/**
 * The read side.
 *
 * Deliberately separate from ListingRepository: browsing needs paging, sorting
 * and joined source data, while ingestion needs whole aggregates. One interface
 * serving both would force every caller to depend on methods it never uses.
 */
interface ListingCatalog
{
    public function browse(ListingFilter $filter): ListingSummaryPage;

    /**
     * Counts for the filter bar.
     *
     * @return array{total: int, byCity: array<string, int>, withPriceDrop: int}
     */
    public function stats(): array;
}
