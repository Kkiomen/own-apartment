<?php

declare(strict_types=1);

namespace App\Domain\Listing\Read;

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;

/**
 * What the person browsing asked to see. Narrowing only — everything stored
 * already matches the search criteria.
 */
final readonly class ListingFilter
{
    public function __construct(
        public ?City $city = null,
        public ?Portal $portal = null,
        public SortOrder $sort = SortOrder::Newest,
        public int $page = 1,
        public int $perPage = 24,
        public bool $onlyPriceDrops = false,
        public ?Price $minPrice = null,
        public ?Price $maxPrice = null,
    ) {}
}
