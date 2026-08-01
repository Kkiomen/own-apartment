<?php

declare(strict_types=1);

use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Search\SearchCriteria;
use App\Infrastructure\Scraping\Support\PageNotFound;
use App\Providers\DomainServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();

    $this->criteria = new SearchCriteria(
        minArea: Area::fromSquareMeters(50),
        minRooms: 3,
        minPrice: Price::fromZloty(300_000),
        maxPrice: Price::fromZloty(600_000),
        cities: [City::Wroclaw],
    );

    $this->fixture = fn (string $dir): string => file_get_contents(base_path("tests/Fixtures/{$dir}/search-wroclaw-page-1.html"));
});

$providers = [
    'gratka' => [DomainServiceProvider::GRATKA, Portal::Gratka, 'gratka.pl/*', 'gratka'],
    'morizon' => [DomainServiceProvider::MORIZON, Portal::Morizon, 'www.morizon.pl/*', 'morizon'],
    'nieruchomosci-online' => [DomainServiceProvider::NIERUCHOMOSCI_ONLINE, Portal::NieruchomosciOnline, 'www.nieruchomosci-online.pl/*', 'nieruchomosci-online'],
];

it('is registered as the adapter for its portal', function (string $abstract, Portal $portal): void {
    expect(app($abstract)->portal())->toBe($portal);
})->with($providers);

it('fetches and yields listings', function (string $abstract, Portal $portal, string $host, string $dir): void {
    Http::fake([$host => Http::response(($this->fixture)($dir))]);
    config()->set('search.scraping.max_pages_per_city', 1);
    config()->set('search.scraping.max_pages_override', []);

    $listings = iterator_to_array(app($abstract)->fetch($this->criteria), preserve_keys: false);

    expect($listings)->toHaveCount(3)
        ->and($listings[0]->portal)->toBe($portal);
})->with($providers);

it('stops paginating as soon as a page comes back empty', function (string $abstract, Portal $portal, string $host, string $dir): void {
    // These portals publish no page count, so an empty page is the only signal
    // that a city is exhausted. Without it every scan would run to the ceiling.
    config()->set('search.scraping.max_pages_per_city', 10);
    config()->set('search.scraping.max_pages_override', []);

    Http::fakeSequence()
        ->push(($this->fixture)($dir))
        ->push('<html><body>brak wyników</body></html>');

    iterator_to_array(app($abstract)->fetch($this->criteria), preserve_keys: false);

    Http::assertSentCount(2);
})->with($providers);

it('treats a 404 past the first page as the end of the results', function (string $abstract, Portal $portal, string $host, string $dir): void {
    // Gratka and Morizon answer 404 instead of serving an empty page once the
    // results run out. Treating that as a failure threw away every listing
    // gathered in the same scan.
    config()->set('search.scraping.max_pages_per_city', 10);
    config()->set('search.scraping.max_pages_override', []);

    Http::fakeSequence()
        ->push(($this->fixture)($dir))
        ->push('nie znaleziono', 404);

    $listings = iterator_to_array(app($abstract)->fetch($this->criteria), preserve_keys: false);

    expect($listings)->toHaveCount(3);
    Http::assertSentCount(2);
})->with($providers);

it('still reports a 404 on the very first page', function (): void {
    // There it means something broke — usually a city slug that stopped
    // resolving, exactly what happened to Morizon and Tomaszów Mazowiecki.
    Http::fake(['gratka.pl/*' => Http::response('nie znaleziono', 404)]);
    config()->set('search.scraping.max_pages_per_city', 5);

    iterator_to_array(app(DomainServiceProvider::GRATKA)->fetch($this->criteria), preserve_keys: false);
})->throws(PageNotFound::class);

it('asks Gratka to pre-filter by our budget and area', function (): void {
    Http::fake(['gratka.pl/*' => Http::response(($this->fixture)('gratka'))]);
    config()->set('search.scraping.max_pages_per_city', 1);

    iterator_to_array(app(DomainServiceProvider::GRATKA)->fetch($this->criteria), preserve_keys: false);

    Http::assertSent(function ($request): bool {
        $url = urldecode($request->url());

        return str_contains($url, '/nieruchomosci/mieszkania/wroclaw/sprzedaz')
            && str_contains($url, 'cena-calkowita:min=300000')
            && str_contains($url, 'cena-calkowita:max=600000')
            && str_contains($url, 'powierzchnia-w-m2:min=50');
    });
});

it('asks Morizon to pre-filter by our budget and area', function (): void {
    Http::fake(['www.morizon.pl/*' => Http::response(($this->fixture)('morizon'))]);
    config()->set('search.scraping.max_pages_per_city', 1);

    iterator_to_array(app(DomainServiceProvider::MORIZON)->fetch($this->criteria), preserve_keys: false);

    Http::assertSent(function ($request): bool {
        $url = urldecode($request->url());

        return str_contains($url, '/mieszkania/wroclaw/')
            && str_contains($url, 'ps[price_from]=300000')
            && str_contains($url, 'ps[price_to]=600000')
            && str_contains($url, 'ps[living_area_from]=50');
    });
});

it('falls back to the county for Tomaszów Mazowiecki on Morizon', function (): void {
    // Verified against the live site: every city-level slug 404s, only the
    // county resolves. Gratka, on the same index, does have the city slug.
    Http::fake(['www.morizon.pl/*' => Http::response(($this->fixture)('morizon'))]);
    config()->set('search.scraping.max_pages_per_city', 1);

    $criteria = new SearchCriteria(
        minArea: Area::fromSquareMeters(50),
        minRooms: 3,
        minPrice: Price::fromZloty(300_000),
        maxPrice: Price::fromZloty(600_000),
        cities: [City::TomaszowMazowiecki],
    );

    iterator_to_array(app(DomainServiceProvider::MORIZON)->fetch($criteria), preserve_keys: false);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/mieszkania/tomaszowski/'));
});

it('keeps the city slug for Tomaszów Mazowiecki on Gratka', function (): void {
    Http::fake(['gratka.pl/*' => Http::response(($this->fixture)('gratka'))]);
    config()->set('search.scraping.max_pages_per_city', 1);

    $criteria = new SearchCriteria(
        minArea: Area::fromSquareMeters(50),
        minRooms: 3,
        minPrice: Price::fromZloty(300_000),
        maxPrice: Price::fromZloty(600_000),
        cities: [City::TomaszowMazowiecki],
    );

    iterator_to_array(app(DomainServiceProvider::GRATKA)->fetch($criteria), preserve_keys: false);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/mieszkania/tomaszow-mazowiecki/sprzedaz'));
});

it('gets a deeper page ceiling for Nieruchomości-online', function (): void {
    // Its price and area filters are ignored server-side, so the unfiltered set
    // needs far more pages to cover the same ground. Correctness is unaffected
    // — the domain filters every listing regardless.
    Http::fake(['www.nieruchomosci-online.pl/*' => Http::response(($this->fixture)('nieruchomosci-online'))]);
    config()->set('search.scraping.max_pages_per_city', 2);
    config()->set('search.scraping.max_pages_override', ['nieruchomosci-online' => 5]);

    iterator_to_array(app(DomainServiceProvider::NIERUCHOMOSCI_ONLINE)->fetch($this->criteria), preserve_keys: false);

    Http::assertSentCount(5);
});
