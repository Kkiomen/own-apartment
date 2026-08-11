<?php

declare(strict_types=1);

namespace App\Domain\Listing\Port;

use App\Domain\Listing\OfferSnapshot;
use App\Domain\Listing\SourceReference;

/**
 * Reading one advert's own page.
 *
 * Separate port from ListingProvider (ISP): a search sweep reads result pages
 * and yields many listings, this reads one offer page and reports on that one
 * advert. Nothing about a portal's search URLs or pagination is needed here.
 */
interface OfferInspector
{
    public function inspect(SourceReference $source): OfferSnapshot;
}
