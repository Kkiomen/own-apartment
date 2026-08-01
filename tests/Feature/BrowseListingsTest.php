<?php

declare(strict_types=1);

use App\Application\Listing\IngestListing;
use App\Domain\Listing\ListingData;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use Inertia\Testing\AssertableInertia;
use Tests\Support\ListingDataFactory;

function ingest(...$args): void
{
    app(IngestListing::class)(ListingDataFactory::matching(...$args), ListingDataFactory::criteria());
}

it('shows an empty state before the first scan', function (): void {
    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Listings')
            ->has('listings', 0)
            ->where('stats.total', 0),
    );
});

it('lists what the scan found', function (): void {
    ingest(externalId: 'olx-1', priceZloty: 549_000);
    ingest(externalId: 'olx-2', city: City::Lodz, areaM2: 61.0, priceZloty: 420_000);

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->component('Listings')
            ->has('listings', 2)
            ->where('stats.total', 2)
            ->where('stats.byCity.lodz', 1)
            ->where('stats.byCity.wroclaw', 1),
    );
});

it('shows a listing with everything the screen needs', function (): void {
    ingest(externalId: 'olx-1', areaM2: 56.0, rooms: 3, priceZloty: 560_000);

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('listings.0.price', '560 000 zł')
            ->where('listings.0.pricePerMeter', '10 000 zł/m²')
            ->where('listings.0.area', '56 m²')
            ->where('listings.0.rooms', 3)
            ->where('listings.0.city', 'Wrocław')
            ->has('listings.0.sources', 1),
    );
});

it('filters by city', function (): void {
    ingest(externalId: 'olx-1');
    ingest(externalId: 'olx-2', city: City::Warszawa, areaM2: 62.0, priceZloty: 590_000);

    $this->get('/?city=warszawa')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('listings', 1)
            ->where('listings.0.city', 'Warszawa'),
    );
});

it('filters by portal', function (): void {
    ingest(externalId: 'olx-1', priceZloty: 500_000);
    ingest(portal: Portal::Otodom, externalId: 'oto-1', areaM2: 70.0, priceZloty: 580_000);

    $this->get('/?portal=otodom')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('listings', 1),
    );
});

it('sorts by the portal publication date, not by when we scanned it', function (): void {
    // Everything from one scan shares a first_seen_at, so ordering on that
    // alone degenerates into "whichever portal ran last" and buries the rest.
    $criteria = ListingDataFactory::criteria();

    $older = ListingDataFactory::matching(externalId: 'olx-old', areaM2: 55.0, priceZloty: 500_000);
    $newer = ListingDataFactory::matching(externalId: 'olx-new', areaM2: 65.0, priceZloty: 560_000);

    app(IngestListing::class)(new ListingData(
        portal: $older->portal, externalId: $older->externalId, url: $older->url, title: 'Starsze',
        city: $older->city(), area: $older->area(), rooms: $older->rooms(), price: $older->price(),
        publishedAt: new DateTimeImmutable('2026-06-01 09:00:00'),
    ), $criteria);

    app(IngestListing::class)(new ListingData(
        portal: $newer->portal, externalId: $newer->externalId, url: $newer->url, title: 'Nowsze',
        city: $newer->city(), area: $newer->area(), rooms: $newer->rooms(), price: $newer->price(),
        publishedAt: new DateTimeImmutable('2026-07-20 09:00:00'),
    ), $criteria);

    $this->get('/?sort=newest')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('listings.0.title', 'Nowsze'),
    );
});

it('still orders listings with no publication date by when we first saw them', function (): void {
    // Gratka, Morizon and Nieruchomości-online publish no date. They must not
    // be pushed off the end of the list because of it.
    ingest(externalId: 'olx-1', areaM2: 55.0, priceZloty: 500_000);
    ingest(externalId: 'olx-2', areaM2: 65.0, priceZloty: 560_000);

    $this->get('/?sort=newest')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('listings', 2),
    );
});

it('tells the list how long the advert has been up on the portal', function (): void {
    $criteria = ListingDataFactory::criteria();
    $data = ListingDataFactory::matching(externalId: 'olx-1');

    app(IngestListing::class)(new ListingData(
        portal: $data->portal, externalId: $data->externalId, url: $data->url, title: $data->title,
        city: $data->city(), area: $data->area(), rooms: $data->rooms(), price: $data->price(),
        publishedAt: new DateTimeImmutable('2026-06-18 09:00:00'),
    ), $criteria);

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where(
            'listings.0.publishedAt',
            fn (string $published): bool => str_starts_with($published, '2026-06-18'),
        ),
    );
});

it('leaves the publication date null for portals that do not publish one', function (): void {
    // Gratka, Morizon and Nieruchomości-online. The list falls back to how
    // long we have been watching rather than inventing a date.
    ingest(portal: Portal::Gratka, externalId: 'gratka-1');

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('listings.0.publishedAt', null),
    );
});

it('filters by a price ceiling', function (): void {
    ingest(externalId: 'olx-cheap', areaM2: 52.0, priceZloty: 420_000);
    ingest(externalId: 'olx-dear', areaM2: 72.0, priceZloty: 580_000);

    $this->get('/?priceTo=500000')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('listings', 1)
            ->where('listings.0.priceZloty', 420_000)
            ->where('filters.priceTo', 500_000),
    );
});

it('filters by a price range', function (): void {
    ingest(externalId: 'olx-1', areaM2: 51.0, priceZloty: 330_000);
    ingest(externalId: 'olx-2', areaM2: 61.0, priceZloty: 460_000);
    ingest(externalId: 'olx-3', areaM2: 71.0, priceZloty: 590_000);

    $this->get('/?priceFrom=400000&priceTo=500000')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('listings', 1)
            ->where('listings.0.priceZloty', 460_000),
    );
});

it('includes a listing priced exactly on the boundary', function (): void {
    ingest(externalId: 'olx-1', priceZloty: 500_000);

    $this->get('/?priceTo=500000')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('listings', 1),
    );
});

it('treats an empty or zero price box as no filter at all', function (string $query): void {
    // An empty input must not quietly filter every listing out of existence.
    ingest(externalId: 'olx-1', priceZloty: 549_000);

    $this->get("/?{$query}")->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('listings', 1)
            ->where('filters.priceTo', null),
    );
})->with([
    'empty' => ['priceTo='],
    'zero' => ['priceTo=0'],
    'nonsense' => ['priceTo=abc'],
]);

it('sorts by price', function (): void {
    ingest(externalId: 'olx-cheap', areaM2: 51.0, priceZloty: 320_000);
    ingest(externalId: 'olx-dear', areaM2: 71.0, priceZloty: 590_000);

    $this->get('/?sort=price_asc')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('listings.0.priceZloty', 320_000),
    );

    $this->get('/?sort=price_desc')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('listings.0.priceZloty', 590_000),
    );
});

it('can show only flats whose price dropped', function (): void {
    ingest(externalId: 'olx-1', priceZloty: 549_000);
    ingest(externalId: 'olx-1', priceZloty: 519_000);
    ingest(externalId: 'olx-2', areaM2: 63.0, priceZloty: 480_000);

    $this->get('/?drops=1')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('listings', 1)
            ->where('listings.0.priceZloty', 519_000)
            // Shown as a negative number: the flat got 30 000 zł cheaper.
            ->where('listings.0.totalPriceChangeZloty', -30_000)
            ->where('stats.withPriceDrop', 1),
    );
});

it('shows one card with two links when both portals advertise the same flat', function (): void {
    ingest(portal: Portal::Olx, externalId: 'olx-1');
    ingest(portal: Portal::Otodom, externalId: 'oto-1');

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('listings', 1)
            ->has('listings.0.sources', 2),
    );
});

it('shows the price history on the card', function (): void {
    ingest(externalId: 'olx-1', priceZloty: 560_000);
    ingest(externalId: 'olx-1', priceZloty: 530_000);
    ingest(externalId: 'olx-1', priceZloty: 499_000);

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('listings.0.priceHistory', 2)
            // Newest first: what a price did recently matters most.
            ->where('listings.0.priceHistory.0.from', '530 000 zł')
            ->where('listings.0.priceHistory.0.to', '499 000 zł')
            ->where('listings.0.priceHistory.0.percent', -5.8),
    );
});

it('shows one button per portal even when a flat is advertised twice there', function (): void {
    // Two agents posting the same flat on OLX would otherwise render as two
    // identical buttons.
    ingest(externalId: 'olx-1');
    ingest(externalId: 'olx-2');

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('listings', 1)
            ->has('listings.0.sources', 1)
            ->where('listings.0.sources.0.portal', 'olx')
            // The duplicate is still counted so it stays visible.
            ->where('listings.0.sources.0.adverts', 2),
    );
});

it('warns about a listing too cheap per square meter to be a flat', function (): void {
    ingest(externalId: 'olx-odd', areaM2: 226.0, rooms: 4, priceZloty: 350_000);

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            // Flagged, not hidden: deciding to ignore it is the buyer's call.
            ->has('listings', 1)
            ->where('listings.0.warning', 'Podejrzanie tanio za m² — sprawdź, czy to na pewno całe mieszkanie'),
    );
});

it('leaves an ordinary flat unflagged', function (): void {
    ingest(externalId: 'olx-1', areaM2: 56.0, priceZloty: 549_000);

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->where('listings.0.warning', null),
    );
});

it('tells the browser which criteria produced this list', function (): void {
    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->where('criteria.minArea', '50 m²')
            ->where('criteria.minRooms', 3)
            ->where('criteria.minPrice', '300 000 zł')
            ->where('criteria.maxPrice', '600 000 zł'),
    );
});
