<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use InvalidArgumentException;

/**
 * Usable floor area in square meters.
 */
final readonly class Area
{
    private function __construct(public float $squareMeters)
    {
        if ($squareMeters <= 0) {
            throw new InvalidArgumentException("Area must be positive, got {$squareMeters} m².");
        }

        // A 5000 m² "flat" means the portal printed the plot area or a typo.
        if ($squareMeters > 1000) {
            throw new InvalidArgumentException("Area {$squareMeters} m² is implausible for a flat.");
        }
    }

    public static function fromSquareMeters(float $squareMeters): self
    {
        return new self(round($squareMeters, 2));
    }

    public function isAtLeast(self $other): bool
    {
        return $this->squareMeters >= $other->squareMeters;
    }

    public function format(): string
    {
        return rtrim(rtrim(number_format($this->squareMeters, 2, ',', ' '), '0'), ',').' m²';
    }
}
