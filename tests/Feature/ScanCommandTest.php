<?php

declare(strict_types=1);

use App\Application\Listing\IngestListing;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Support\ListingDataFactory;

use function Pest\Laravel\artisan;

beforeEach(function (): void {
    Sleep::fake();

    // Every portal answers with a page holding no listings. The scan then runs
    // end to end and succeeds without touching the network — which is what
    // these tests are about, rather than parsing.
    Http::fake(['*' => Http::response('<html><body></body></html>')]);
});

it('scans when there is nothing yet', function (): void {
    // The first-run service on a fresh deployment.
    artisan('listings:scan --if-empty')
        ->doesntExpectOutputToContain('Listings already present')
        ->assertSuccessful();

    Http::assertSentCount(20); // 5 portals x 4 cities
});

it('skips the initial scan once listings exist', function (): void {
    // Restarting the stack must not re-scrape five portals.
    app(IngestListing::class)(
        ListingDataFactory::matching(),
        ListingDataFactory::criteria(),
    );

    artisan('listings:scan --if-empty')
        ->expectsOutputToContain('Listings already present')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('always scans without the flag', function (): void {
    app(IngestListing::class)(
        ListingDataFactory::matching(),
        ListingDataFactory::criteria(),
    );

    artisan('listings:scan')
        ->doesntExpectOutputToContain('Listings already present')
        ->assertSuccessful();

    Http::assertSentCount(20);
});
