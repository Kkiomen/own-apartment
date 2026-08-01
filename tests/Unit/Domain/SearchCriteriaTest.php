<?php

declare(strict_types=1);

use App\Domain\Search\SearchCriteria;
use Tests\Support\ListingDataFactory;

it('accepts a flat that meets every criterion', function (): void {
    $criteria = ListingDataFactory::criteria();
    $listing = ListingDataFactory::matching(areaM2: 56, rooms: 3, priceZloty: 549_000);

    expect($criteria->isSatisfiedBy($listing))->toBeTrue()
        ->and($criteria->rejectionReasons($listing))->toBe([]);
});

it('treats the limits as hard, with no tolerance margin', function (): void {
    $criteria = ListingDataFactory::criteria();

    // Exactly on the boundary is in.
    expect($criteria->isSatisfiedBy(ListingDataFactory::matching(areaM2: 50)))->toBeTrue()
        ->and($criteria->isSatisfiedBy(ListingDataFactory::matching(priceZloty: 600_000)))->toBeTrue()
        ->and($criteria->isSatisfiedBy(ListingDataFactory::matching(priceZloty: 300_000)))->toBeTrue();

    // A hair outside is out. We decided against a margin on purpose.
    expect($criteria->isSatisfiedBy(ListingDataFactory::matching(areaM2: 49.9)))->toBeFalse()
        ->and($criteria->isSatisfiedBy(ListingDataFactory::matching(priceZloty: 600_001)))->toBeFalse()
        ->and($criteria->isSatisfiedBy(ListingDataFactory::matching(priceZloty: 299_999)))->toBeFalse();
});

it('accepts more than three rooms', function (): void {
    // "min 3 rooms": a 4-room flat inside the budget is a bonus, not a miss.
    $criteria = ListingDataFactory::criteria();

    expect($criteria->isSatisfiedBy(ListingDataFactory::matching(rooms: 4)))->toBeTrue()
        ->and($criteria->isSatisfiedBy(ListingDataFactory::matching(rooms: 2)))->toBeFalse();
});

it('explains why a flat was rejected instead of dropping it silently', function (): void {
    $criteria = ListingDataFactory::criteria();
    $tooSmallAndTooExpensive = ListingDataFactory::matching(areaM2: 42, rooms: 2, priceZloty: 750_000);

    expect($criteria->rejectionReasons($tooSmallAndTooExpensive))
        ->toBe(['area_too_small', 'too_few_rooms', 'price_above_budget']);
});

it('builds from the app config', function (): void {
    $criteria = SearchCriteria::fromConfig([
        'min_area_m2' => 50.0,
        'min_rooms' => 3,
        'min_price_pln' => 300_000,
        'max_price_pln' => 600_000,
        'cities' => ['wroclaw', 'warszawa', 'lodz', 'tomaszow-mazowiecki'],
    ]);

    expect($criteria->minArea->squareMeters)->toBe(50.0)
        ->and($criteria->maxPrice->zloty())->toBe(600_000.0)
        ->and($criteria->cities)->toHaveCount(4);
});

it('refuses a budget range that cannot be satisfied', function (): void {
    SearchCriteria::fromConfig([
        'min_area_m2' => 50.0,
        'min_rooms' => 3,
        'min_price_pln' => 600_000,
        'max_price_pln' => 300_000,
        'cities' => ['wroclaw'],
    ]);
})->throws(InvalidArgumentException::class);
