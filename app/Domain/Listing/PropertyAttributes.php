<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Price;

/**
 * The four facts SearchCriteria needs to judge a flat.
 *
 * Kept deliberately tiny (ISP) so criteria can be evaluated against both an
 * incoming ListingData from a scraper and a stored Listing aggregate, without
 * either having to know about the other.
 */
interface PropertyAttributes
{
    public function city(): City;

    public function area(): Area;

    public function rooms(): int;

    public function price(): Price;
}
