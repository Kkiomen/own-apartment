<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use DateTimeImmutable;

/**
 * One listing as a portal reported it, already normalised into our own types.
 *
 * This is the contract every scraping adapter must produce. Anything portal-
 * specific (raw HTML, their JSON shape, their field names) stops here and never
 * reaches the domain.
 */
final readonly class ListingData implements PropertyAttributes
{
    /**
     * @param  list<string>  $images
     */
    public function __construct(
        public Portal $portal,
        public string $externalId,
        public string $url,
        public string $title,
        private City $city,
        private Area $area,
        private int $rooms,
        private Price $price,
        public ?string $district = null,
        public ?int $floor = null,
        public array $images = [],
        public ?DateTimeImmutable $publishedAt = null,
        public ?string $description = null,
        public ?bool $isPrivateSeller = null,
    ) {}

    public function city(): City
    {
        return $this->city;
    }

    public function area(): Area
    {
        return $this->area;
    }

    public function rooms(): int
    {
        return $this->rooms;
    }

    public function price(): Price
    {
        return $this->price;
    }

    public function fingerprint(): Fingerprint
    {
        return Fingerprint::of($this->city, $this->area, $this->rooms, $this->price);
    }
}
