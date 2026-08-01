<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

use InvalidArgumentException;

/**
 * A price in PLN, stored as an integer number of grosze.
 *
 * Integer-only on purpose: floats silently turn 599_900.00 into 599_899.99 and
 * that is exactly the kind of bug that makes a flat miss the 600k filter.
 */
final readonly class Price
{
    private function __construct(public int $grosze)
    {
        if ($grosze < 0) {
            throw new InvalidArgumentException("Price cannot be negative, got {$grosze} grosze.");
        }
    }

    public static function fromGrosze(int $grosze): self
    {
        return new self($grosze);
    }

    public static function fromZloty(int|float $zloty): self
    {
        return new self((int) round($zloty * 100));
    }

    public function zloty(): float
    {
        return $this->grosze / 100;
    }

    /**
     * Price per square meter — the number that actually tells you whether a
     * listing is a bargain or just small.
     */
    public function per(Area $area): self
    {
        return new self((int) round($this->grosze / $area->squareMeters));
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->grosze > $other->grosze;
    }

    public function isLessThan(self $other): bool
    {
        return $this->grosze < $other->grosze;
    }

    public function equals(self $other): bool
    {
        return $this->grosze === $other->grosze;
    }

    public function differenceTo(self $other): int
    {
        return $other->grosze - $this->grosze;
    }

    public function format(): string
    {
        return number_format($this->zloty(), 0, ',', ' ').' zł';
    }
}
