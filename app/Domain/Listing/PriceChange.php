<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use DateTimeImmutable;

/**
 * A recorded movement in a listing's asking price.
 *
 * Only real changes become one of these — "seen again at the same price" is not
 * a price change and must never be written, or the history table grows by
 * thousands of meaningless rows a day and the chart becomes unreadable.
 */
final readonly class PriceChange
{
    public function __construct(
        public Price $from,
        public Price $to,
        public Portal $observedOn,
        public DateTimeImmutable $observedAt,
    ) {}

    public function isDrop(): bool
    {
        return $this->to->isLessThan($this->from);
    }

    public function differenceInGrosze(): int
    {
        return $this->from->differenceTo($this->to);
    }

    /**
     * Signed percentage, negative for a drop. Rounded to one decimal because
     * nobody cares about 0.03% on a 500k flat.
     */
    public function percentageChange(): float
    {
        return round(($this->differenceInGrosze() / $this->from->grosze) * 100, 1);
    }
}
