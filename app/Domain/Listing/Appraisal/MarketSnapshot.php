<?php

declare(strict_types=1);

namespace App\Domain\Listing\Appraisal;

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Price;

/**
 * What a square meter currently costs in each city, according to the listings
 * we are tracking.
 *
 * The median, not the average: a single 226 m² oddity at 1 549 zł/m² drags an
 * average down far enough to make genuinely overpriced flats look normal.
 */
final readonly class MarketSnapshot
{
    /**
     * @param  array<string, int>  $medianPerSquareMeterGrosze  city value => grosze
     */
    public function __construct(private array $medianPerSquareMeterGrosze) {}

    public function medianFor(City $city): ?Price
    {
        $grosze = $this->medianPerSquareMeterGrosze[$city->value] ?? null;

        return $grosze === null ? null : Price::fromGrosze($grosze);
    }

    /**
     * How far below the city median this listing sits, as a percentage.
     * Negative means cheaper than typical.
     */
    public function deviationPercent(City $city, Price $pricePerSquareMeter): ?float
    {
        $median = $this->medianFor($city);

        if ($median === null || $median->grosze === 0) {
            return null;
        }

        return round((($pricePerSquareMeter->grosze - $median->grosze) / $median->grosze) * 100, 1);
    }
}
