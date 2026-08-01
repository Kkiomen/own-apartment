<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\Price;

/**
 * Flags listings that meet the criteria on paper but are almost certainly not
 * an ordinary flat — a share in a property, a plot, a commercial unit, or a
 * mis-parsed area.
 *
 * Deliberately a *warning*, never a filter. A 226 m² flat in Wrocław at
 * 1 549 zł/m² is noise, but the judgement of what to do about it belongs to the
 * people buying the flat, not to the scraper. Hiding it would also hide the day
 * a parser starts producing nonsense.
 */
final readonly class ListingPlausibility
{
    public function __construct(private Price $minPricePerSquareMeter) {}

    public static function fromZloty(int $minPricePerSquareMeter): self
    {
        return new self(Price::fromZloty($minPricePerSquareMeter));
    }

    public function warningFor(Price $price, Area $area): ?PlausibilityWarning
    {
        return $price->per($area)->isLessThan($this->minPricePerSquareMeter)
            ? PlausibilityWarning::PricePerMeterTooLow
            : null;
    }
}
