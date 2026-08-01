<?php

declare(strict_types=1);

namespace App\Domain\Listing\Appraisal;

use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Price;
use DateTimeImmutable;

/**
 * Everything the appraiser needs about one listing, and nothing else.
 */
final readonly class AppraisalSubject
{
    public function __construct(
        public int $listingId,
        public City $city,
        public Area $area,
        public Price $price,
        public ?string $title = null,
        public ?string $description = null,
        public ?DateTimeImmutable $publishedAt = null,
        public ?bool $isPrivateSeller = null,
        public ?Price $droppedBy = null,
        public ?Price $cheapestElsewhere = null,
    ) {}

    public function pricePerSquareMeter(): Price
    {
        return $this->price->per($this->area);
    }
}
