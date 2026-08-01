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

beforeEach(function (): void {
    Sleep::fake();

    $this->fixture = file_get_contents(base_path('tests/Fixtures/otodom/search-wroclaw-page-1.html'));

    $this->criteria = fn (City ...$cities) => new SearchCriteria(
        minArea: Area::fromSquareMeters(50),
        minRooms: 3,
        minPrice: Price::fromZloty(300_000),
        maxPrice: Price::fromZloty(600_000),
        cities: array_values($cities),
    );

    $this->provider = fn () => app(DomainServiceProvider::OTODOM);
});

it('is the registered adapter for Otodom', function (): void {
    expect(($this->provider)()->portal())->toBe(Portal::Otodom);
});

it('fetches and yields listings', function (): void {
    Http::fake(['www.otodom.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 1);

    $listings = iterator_to_array(($this->provider)()->fetch(($this->criteria)(City::Wroclaw)), preserve_keys: false);

    expect($listings)->toHaveCount(2)
        ->and($listings[0]->portal)->toBe(Portal::Otodom);
});

it('uses the city location path and our filters', function (): void {
    Http::fake(['www.otodom.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 1);

    iterator_to_array(($this->provider)()->fetch(($this->criteria)(City::Wroclaw)), preserve_keys: false);

    Http::assertSent(function ($request): bool {
        $url = urldecode($request->url());

        return str_contains($url, '/sprzedaz/mieszkanie/dolnoslaskie/wroclaw/wroclaw/wroclaw')
            && str_contains($url, 'priceMin=300000')
            && str_contains($url, 'priceMax=600000')
            && str_contains($url, 'areaMin=50');
    });
});

it('falls back to the county path for Tomaszów Mazowiecki', function (): void {
    // Otodom has no working city-level path for Tomaszów — every variant 404s,
    // only `lodzkie/tomaszowski` resolves. The county page mixes in nearby
    // villages, which the domain filters out by city.
    Http::fake(['www.otodom.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 1);

    iterator_to_array(($this->provider)()->fetch(($this->criteria)(City::TomaszowMazowiecki)), preserve_keys: false);

    Http::assertSent(fn ($request): bool => str_contains(
        urldecode($request->url()),
        '/sprzedaz/mieszkanie/lodzkie/tomaszowski?',
    ));
});

it('paginates but never beyond the configured ceiling', function (): void {
    Http::fake(['www.otodom.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 2);

    iterator_to_array(($this->provider)()->fetch(($this->criteria)(City::Wroclaw)), preserve_keys: false);

    // The fixture claims 3 pages; the ceiling is 2.
    Http::assertSentCount(2);
});

it('pauses between requests instead of hammering the portal', function (): void {
    Http::fake(['www.otodom.pl/*' => Http::response($this->fixture)]);
    config()->set('search.scraping.max_pages_per_city', 2);

    iterator_to_array(($this->provider)()->fetch(($this->criteria)(City::Wroclaw)), preserve_keys: false);

    Sleep::assertSleptTimes(2);
});

it('surfaces an HTTP failure rather than silently returning nothing', function (): void {
    Http::fake(['www.otodom.pl/*' => Http::response('blocked', 403)]);
    config()->set('search.scraping.max_pages_per_city', 1);

    iterator_to_array(($this->provider)()->fetch(($this->criteria)(City::Wroclaw)), preserve_keys: false);
})->throws(RuntimeException::class);
