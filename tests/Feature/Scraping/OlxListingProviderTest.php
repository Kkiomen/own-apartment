<?php

declare(strict_types=1);

use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Search\SearchCriteria;
use App\Providers\DomainServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\ListingDataFactory;

beforeEach(function (): void {
    Sleep::fake();

    $this->fixture = file_get_contents(base_path('tests/Fixtures/olx/search-wroclaw-page-1.html'));

    $this->criteria = new SearchCriteria(
        minArea: Area::fromSquareMeters(50),
        minRooms: 3,
        minPrice: Price::fromZloty(300_000),
        maxPrice: Price::fromZloty(600_000),
        cities: [City::Wroclaw],
    );

    $this->provider = fn () => app(DomainServiceProvider::OLX);
});

it('is the registered adapter for OLX', function (): void {
    expect(($this->provider)()->portal())->toBe(Portal::Olx);
});

it('fetches and yields listings', function (): void {
    Http::fake(['www.olx.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 1);

    $listings = iterator_to_array(($this->provider)()->fetch($this->criteria), preserve_keys: false);

    expect($listings)->toHaveCount(3)
        ->and($listings[0]->portal)->toBe(Portal::Olx);
});

it('asks OLX to pre-filter by our budget and area', function (): void {
    Http::fake(['www.olx.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 1);

    iterator_to_array(($this->provider)()->fetch($this->criteria), preserve_keys: false);

    Http::assertSent(function ($request): bool {
        $url = urldecode($request->url());

        return str_contains($url, '/nieruchomosci/mieszkania/sprzedaz/wroclaw/')
            && str_contains($url, 'filter_float_price:from]=300000')
            && str_contains($url, 'filter_float_price:to]=600000')
            && str_contains($url, 'filter_float_m:from]=50');
    });
});

it('does not ask OLX to sort, because OLX ignores it', function (): void {
    // Verified live: with search[order]=created_at:desc, page one still mixed
    // adverts from March and August. Asking for an order we do not get invites
    // the assumption that page one is the newest — it is not, so every page
    // must be walked instead.
    Http::fake(['www.olx.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 1);

    iterator_to_array(($this->provider)()->fetch($this->criteria), preserve_keys: false);

    Http::assertSent(fn ($request): bool => ! str_contains(urldecode($request->url()), 'search[order]'));
});

it('paginates but never beyond the configured ceiling', function (): void {
    // The fixture claims totalPages = 2. With the ceiling at 1 we must stop
    // after one request — a portal reporting a bogus page count cannot be
    // allowed to turn one scan into thousands of requests.
    Http::fake(['www.olx.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 1);

    iterator_to_array(($this->provider)()->fetch($this->criteria), preserve_keys: false);

    Http::assertSentCount(1);
});

it('follows pagination up to the page count OLX reports', function (): void {
    Http::fake(['www.olx.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 25);

    $listings = iterator_to_array(($this->provider)()->fetch($this->criteria), preserve_keys: false);

    Http::assertSentCount(2);
    expect($listings)->toHaveCount(6);
});

it('pauses between requests instead of hammering the portal', function (): void {
    Http::fake(['www.olx.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 25);

    iterator_to_array(($this->provider)()->fetch($this->criteria), preserve_keys: false);

    Sleep::assertSleptTimes(2);
});

it('scans every city we care about', function (): void {
    Http::fake(['www.olx.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 1);

    iterator_to_array(($this->provider)()->fetch(ListingDataFactory::criteria()), preserve_keys: false);

    Http::assertSentCount(4);
});

it('surfaces an HTTP failure rather than silently returning nothing', function (): void {
    Http::fake(['www.olx.pl/*' => Http::response('rate limited', 429)]);
    config()->set('search.scraping.max_pages_per_city', 1);

    iterator_to_array(($this->provider)()->fetch($this->criteria), preserve_keys: false);
})->throws(RuntimeException::class);
