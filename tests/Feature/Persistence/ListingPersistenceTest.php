<?php

declare(strict_types=1);

use App\Application\Listing\IngestListing;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\Portal;
use App\Infrastructure\Persistence\Eloquent\EloquentListingRepository;
use Tests\Support\ListingDataFactory;

/*
 * The domain is covered by fast in-memory tests. These exist to prove the
 * Eloquent adapter honours the same contract — mapping, transactions and the
 * unique index are exactly the things a fake cannot verify.
 */

it('round-trips a listing through the database without losing anything', function (): void {
    $repository = app(ListingRepository::class);
    $data = ListingDataFactory::matching(areaM2: 56.5, rooms: 3, priceZloty: 549_000);

    app(IngestListing::class)($data, ListingDataFactory::criteria());

    $loaded = $repository->findBySource(Portal::Olx, 'olx-1');

    expect($loaded)->not->toBeNull()
        ->and($loaded->area()->squareMeters)->toBe(56.5)
        ->and($loaded->rooms())->toBe(3)
        // Grosze survive the round trip: this is where a float column would
        // quietly corrupt the price.
        ->and($loaded->price()->grosze)->toBe(54_900_000)
        ->and($loaded->city())->toBe($data->city())
        ->and($loaded->sources())->toHaveCount(1);
});

it('uses the Eloquent adapter in the real container', function (): void {
    expect(app(ListingRepository::class))->toBeInstanceOf(EloquentListingRepository::class);
});

it('persists a price change as one history row', function (): void {
    $criteria = ListingDataFactory::criteria();

    app(IngestListing::class)(ListingDataFactory::matching(priceZloty: 549_000), $criteria);
    app(IngestListing::class)(ListingDataFactory::matching(priceZloty: 519_000), $criteria);

    $this->assertDatabaseCount('listings', 1);
    $this->assertDatabaseCount('price_changes', 1);
    $this->assertDatabaseHas('price_changes', [
        'price_from_grosze' => 54_900_000,
        'price_to_grosze' => 51_900_000,
    ]);
});

it('does not write a history row when nothing changed', function (): void {
    $criteria = ListingDataFactory::criteria();

    app(IngestListing::class)(ListingDataFactory::matching(), $criteria);
    app(IngestListing::class)(ListingDataFactory::matching(), $criteria);

    $this->assertDatabaseCount('price_changes', 0);
});

it('stores one listing with two sources when both portals advertise it', function (): void {
    $criteria = ListingDataFactory::criteria();

    app(IngestListing::class)(ListingDataFactory::matching(portal: Portal::Olx, externalId: 'olx-1'), $criteria);
    app(IngestListing::class)(ListingDataFactory::matching(portal: Portal::Otodom, externalId: 'oto-9'), $criteria);

    $this->assertDatabaseCount('listings', 1);
    $this->assertDatabaseCount('listing_sources', 2);
});
