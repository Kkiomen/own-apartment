<?php

declare(strict_types=1);

namespace App\Domain\Listing\Port;

use App\Domain\Listing\Listing;
use App\Domain\Listing\PriceChange;

/**
 * Separate from ListingRepository on purpose (ISP): price history is
 * append-only and read by completely different code than the listing itself.
 */
interface PriceHistoryRepository
{
    public function record(Listing $listing, PriceChange $change): void;
}
