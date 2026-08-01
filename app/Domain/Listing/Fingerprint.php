<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Price;

/**
 * Identity of a physical flat across portals.
 *
 * The same flat is routinely posted on OLX and Otodom by the same agent, and we
 * want it in the list once, with one price history.
 *
 * Price is part of the fingerprint on purpose. Without it, every 3-room 52 m²
 * flat in Warszawa collapses into a single row — a false merge, which loses
 * real listings and is invisible once it happens. Including it means the same
 * flat advertised at two different prices stays split: a false *split*, which
 * is merely a duplicate row you can see and reason about.
 *
 * Prefer the visible failure over the invisible one.
 */
final readonly class Fingerprint
{
    private function __construct(public string $value) {}

    public static function of(City $city, Area $area, int $rooms, Price $price): self
    {
        return new self(sprintf(
            '%s|%.1f|%d|%d',
            $city->value,
            $area->squareMeters,
            $rooms,
            $price->grosze,
        ));
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
