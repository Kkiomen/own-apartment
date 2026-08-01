<?php

declare(strict_types=1);

use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Search\SearchCriteria;
use App\Infrastructure\Scraping\Support\PortalRefused;
use App\Providers\DomainServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();
    Http::preventStrayRequests();

    // Four cities, so a portal that keeps refusing has plenty of rope to hang
    // itself with if the breaker does not trip.
    $this->criteria = new SearchCriteria(
        minArea: Area::fromSquareMeters(50),
        minRooms: 3,
        minPrice: Price::fromZloty(300_000),
        maxPrice: Price::fromZloty(600_000),
        cities: [City::Wroclaw, City::Warszawa, City::Lodz, City::TomaszowMazowiecki],
    );

    $this->provider = fn () => app(DomainServiceProvider::OLX);
});

it('stops scanning a portal that keeps turning us away', function (): void {
    Http::fake(['*' => Http::response('', 429)]);

    $fetch = fn () => iterator_to_array(($this->provider)()->fetch($this->criteria));

    expect($fetch)->toThrow(PortalRefused::class);

    // Three refusals and out — not one per page across four cities. Without
    // the breaker this is 25 pages x 4 cities of knocking on a closed door.
    Http::assertSentCount(3);
});

it('reads the cities it still can, then reports the sweep as failed', function (): void {
    // Refused on the first city, fine on the rest — below the breaker's
    // threshold, so the remaining cities are still swept.
    Http::fake([
        '*' => Http::sequence()
            ->push('', 429)
            ->whenEmpty(Http::response('<html></html>', 200)),
    ]);

    $fetch = fn () => iterator_to_array(($this->provider)()->fetch($this->criteria));

    // Failing loudly is the point: ScanPortals only retires adverts for a
    // portal that scanned cleanly, so a refused sweep reported as a success
    // would let a rate limit deactivate listings we never got to read.
    expect($fetch)->toThrow(PortalRefused::class);

    // One request per city — the three readable ones were not skipped.
    Http::assertSentCount(4);
});

it('leaves an ordinary parse failure alone', function (): void {
    // A 200 with markup we cannot read is not a refusal and must not trip the
    // breaker — that path is the parser's problem, not the rate limiter's.
    Http::fake(['*' => Http::response('<html>nothing useful</html>')]);

    $listings = iterator_to_array(($this->provider)()->fetch($this->criteria));

    expect($listings)->toBe([]);
    Http::assertSentCount(4);
});
