<?php

declare(strict_types=1);

use App\Application\Listing\IngestionOutcome;
use App\Application\Listing\IngestListing;
use App\Domain\Alert\AlertReason;
use App\Domain\Listing\ListingData;
use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Search\SearchCriteria;
use Tests\Support\FrozenClock;
use Tests\Support\InMemoryListingRepository;
use Tests\Support\InMemoryPriceHistoryRepository;
use Tests\Support\ListingDataFactory;
use Tests\Support\RecordingAlertNotifier;

beforeEach(function (): void {
    $this->listings = new InMemoryListingRepository;
    $this->priceHistory = new InMemoryPriceHistoryRepository;
    $this->notifier = new RecordingAlertNotifier;
    $this->clock = FrozenClock::at('2026-08-01 09:00:00');

    $this->ingest = new IngestListing(
        $this->listings,
        $this->priceHistory,
        $this->notifier,
        $this->clock,
    );

    $this->criteria = ListingDataFactory::criteria();
});

it('stores a matching flat and raises a new-match alert', function (): void {
    $result = ($this->ingest)(ListingDataFactory::matching(), $this->criteria);

    expect($result->outcome)->toBe(IngestionOutcome::Created)
        ->and($this->listings->count())->toBe(1)
        ->and($this->notifier->withReason(AlertReason::NewMatch))->toHaveCount(1);
});

it('rejects a flat outside the criteria without storing or alerting', function (array $overrides, string $expectedReason): void {
    $result = ($this->ingest)(ListingDataFactory::matching(...$overrides), $this->criteria);

    expect($result->outcome)->toBe(IngestionOutcome::Rejected)
        ->and($result->rejectionReasons)->toContain($expectedReason)
        ->and($this->listings->count())->toBe(0)
        ->and($this->notifier->count())->toBe(0);
})->with([
    'too small' => [['areaM2' => 48.0], 'area_too_small'],
    'too few rooms' => [['rooms' => 2], 'too_few_rooms'],
    'over budget' => [['priceZloty' => 650_000], 'price_above_budget'],
    'below range' => [['priceZloty' => 250_000], 'price_below_range'],
]);

it('does not trust an adapter that ignored the city filter', function (): void {
    // A portal quietly dropping a query parameter is a real failure mode, and
    // the domain re-checking every result is what keeps it from flooding us.
    $krakow = new ListingData(
        portal: Portal::Olx,
        externalId: 'olx-krk',
        url: 'https://www.olx.pl/oferta/olx-krk',
        title: 'Mieszkanie 3 pokoje',
        city: City::Wroclaw,
        area: Area::fromSquareMeters(60),
        rooms: 3,
        price: Price::fromZloty(500_000),
    );

    $narrowCriteria = new SearchCriteria(
        minArea: Area::fromSquareMeters(50),
        minRooms: 3,
        minPrice: Price::fromZloty(300_000),
        maxPrice: Price::fromZloty(600_000),
        cities: [City::Warszawa],
    );

    $result = ($this->ingest)($krakow, $narrowCriteria);

    expect($result->outcome)->toBe(IngestionOutcome::Rejected)
        ->and($result->rejectionReasons)->toContain('city_not_wanted');
});

it('records nothing when a known flat is seen again at the same price', function (): void {
    ($this->ingest)(ListingDataFactory::matching(), $this->criteria);
    $this->notifier->sent = [];

    $this->clock->advanceTo('2026-08-02 09:00:00');
    $result = ($this->ingest)(ListingDataFactory::matching(), $this->criteria);

    expect($result->outcome)->toBe(IngestionOutcome::Unchanged)
        ->and($this->listings->count())->toBe(1)
        ->and($this->priceHistory->count())->toBe(0)
        ->and($this->notifier->count())->toBe(0);
});

it('records a price drop and alerts', function (): void {
    ($this->ingest)(ListingDataFactory::matching(priceZloty: 549_000), $this->criteria);
    $this->notifier->sent = [];

    $this->clock->advanceTo('2026-08-05 09:00:00');
    $result = ($this->ingest)(ListingDataFactory::matching(priceZloty: 519_000), $this->criteria);

    expect($result->outcome)->toBe(IngestionOutcome::PriceChanged)
        ->and($this->priceHistory->count())->toBe(1)
        ->and($this->notifier->withReason(AlertReason::PriceDrop))->toHaveCount(1)
        ->and($this->listings->all()[0]->price()->zloty())->toBe(519_000.0);
});

it('records a price increase but does not interrupt us about it', function (): void {
    ($this->ingest)(ListingDataFactory::matching(priceZloty: 500_000), $this->criteria);
    $this->notifier->sent = [];

    $this->clock->advanceTo('2026-08-05 09:00:00');
    $result = ($this->ingest)(ListingDataFactory::matching(priceZloty: 540_000), $this->criteria);

    expect($result->outcome)->toBe(IngestionOutcome::PriceChanged)
        ->and($this->priceHistory->count())->toBe(1)
        ->and($this->notifier->count())->toBe(0);
});

it('merges the same flat advertised on two portals into one listing', function (): void {
    // The whole point of the fingerprint: an agent posting to OLX and Otodom
    // must not produce two rows in our list.
    ($this->ingest)(ListingDataFactory::matching(portal: Portal::Olx, externalId: 'olx-1'), $this->criteria);
    ($this->ingest)(ListingDataFactory::matching(portal: Portal::Otodom, externalId: 'oto-9'), $this->criteria);

    expect($this->listings->count())->toBe(1)
        ->and($this->listings->all()[0]->portals())->toBe([Portal::Olx, Portal::Otodom])
        // Only the first sighting is a new match; the second is the same flat.
        ->and($this->notifier->withReason(AlertReason::NewMatch))->toHaveCount(1);
});

it('keeps genuinely different flats apart', function (): void {
    ($this->ingest)(ListingDataFactory::matching(externalId: 'olx-1', priceZloty: 549_000), $this->criteria);
    ($this->ingest)(ListingDataFactory::matching(portal: Portal::Otodom, externalId: 'oto-2', areaM2: 61.0, priceZloty: 580_000), $this->criteria);

    expect($this->listings->count())->toBe(2);
});
