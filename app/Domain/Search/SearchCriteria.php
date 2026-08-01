<?php

declare(strict_types=1);

namespace App\Domain\Search;

use App\Domain\Listing\PropertyAttributes;
use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Price;
use InvalidArgumentException;

/**
 * What we are looking for, as a composable specification.
 *
 * The same object filters a database query (via the repository) and judges a
 * single freshly scraped listing in memory — that is the whole point of keeping
 * it a plain object instead of a query scope.
 *
 * Hard limits by explicit decision: no tolerance margin. A 49 m² flat is not
 * "nearly" what we want, it is a flat we would have to move out of.
 */
final readonly class SearchCriteria
{
    /**
     * @param  non-empty-list<City>  $cities
     */
    public function __construct(
        public Area $minArea,
        public int $minRooms,
        public Price $minPrice,
        public Price $maxPrice,
        public array $cities,
    ) {
        if ($minRooms < 1) {
            throw new InvalidArgumentException("minRooms must be at least 1, got {$minRooms}.");
        }

        if ($maxPrice->isLessThan($minPrice)) {
            throw new InvalidArgumentException('maxPrice cannot be lower than minPrice.');
        }
    }

    /**
     * Build from config/search.php.
     *
     * @param  array{min_area_m2: float, min_rooms: int, min_price_pln: int, max_price_pln: int, cities: list<string>}  $config
     */
    public static function fromConfig(array $config): self
    {
        $cities = array_map(
            static fn (string $city): City => City::from($city),
            $config['cities'],
        );

        if ($cities === []) {
            throw new InvalidArgumentException('At least one city must be configured.');
        }

        return new self(
            minArea: Area::fromSquareMeters($config['min_area_m2']),
            minRooms: $config['min_rooms'],
            minPrice: Price::fromZloty($config['min_price_pln']),
            maxPrice: Price::fromZloty($config['max_price_pln']),
            cities: $cities,
        );
    }

    public function isSatisfiedBy(PropertyAttributes $property): bool
    {
        return $this->rejectionReasons($property) === [];
    }

    /**
     * Why a listing did not qualify.
     *
     * Returned rather than logged so the UI can explain "seen but filtered out"
     * instead of listings vanishing without trace — which is impossible to debug
     * when a scraper starts mis-parsing a field.
     *
     * @return list<string>
     */
    public function rejectionReasons(PropertyAttributes $property): array
    {
        $reasons = [];

        if (! in_array($property->city(), $this->cities, strict: true)) {
            $reasons[] = 'city_not_wanted';
        }

        if (! $property->area()->isAtLeast($this->minArea)) {
            $reasons[] = 'area_too_small';
        }

        if ($property->rooms() < $this->minRooms) {
            $reasons[] = 'too_few_rooms';
        }

        if ($property->price()->isLessThan($this->minPrice)) {
            $reasons[] = 'price_below_range';
        }

        if ($property->price()->isGreaterThan($this->maxPrice)) {
            $reasons[] = 'price_above_budget';
        }

        return $reasons;
    }
}
