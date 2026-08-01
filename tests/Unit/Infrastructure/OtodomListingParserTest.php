<?php

declare(strict_types=1);

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Infrastructure\Scraping\Otodom\OtodomListingParser;
use App\Infrastructure\Scraping\Support\EmbeddedJsonExtractor;
use Psr\Log\NullLogger;

/*
 * Fixture captured from a real Otodom search page, trimmed to six items: two
 * genuine flats plus the four shapes that quietly corrupt a listings list.
 */

beforeEach(function (): void {
    $this->parser = new OtodomListingParser(new EmbeddedJsonExtractor, new NullLogger);
    $this->html = file_get_contents(__DIR__.'/../../Fixtures/otodom/search-wroclaw-page-1.html');
});

it('reads the listings out of __NEXT_DATA__', function (): void {
    $page = $this->parser->parse($this->html);

    expect($page->listings)->toHaveCount(2)
        ->and($page->totalPages)->toBe(3)
        ->and($page->skipped)->toBe(4);
});

it('maps every field we depend on', function (): void {
    $listing = $this->parser->parse($this->html)->listings[0];

    expect($listing->portal)->toBe(Portal::Otodom)
        ->and($listing->externalId)->toBe('67932797')
        ->and($listing->city())->toBe(City::Wroclaw)
        ->and($listing->district)->toBe('Krzyki')
        ->and($listing->area()->squareMeters)->toBe(58.21)
        ->and($listing->rooms())->toBe(2)
        ->and($listing->price()->zloty())->toBe(590_000.0)
        ->and($listing->floor)->toBe(5)
        ->and($listing->publishedAt)->not->toBeNull();
});

it('rebuilds the offer URL from the slug', function (): void {
    // The payload's own `href` is a template containing "[lang]", which would
    // give us links that 404 when clicked from the app.
    $listing = $this->parser->parse($this->html)->listings[0];

    expect($listing->url)->toBe('https://www.otodom.pl/pl/oferta/atrakcyjne-mieszkanie-wroclaw-ul-przyjazni-ID4B2qh');
});

it('collects the gallery and the teaser description', function (): void {
    $listing = $this->parser->parse($this->html)->listings[0];

    expect($listing->images)->toHaveCount(3)
        ->and($listing->description)->not->toBeNull();
});

it('translates Otodom word-based rooms and floors', function (): void {
    $listings = $this->parser->parse($this->html)->listings;

    expect($listings[0]->rooms())->toBe(2)   // "TWO"
        ->and($listings[1]->rooms())->toBe(3) // "THREE"
        ->and($listings[1]->floor)->toBe(1);  // "FIRST"
});

it('drops everything that is not a flat for sale', function (string $expectation, string $externalId): void {
    $ids = array_map(static fn ($l): string => $l->externalId, $this->parser->parse($this->html)->listings);

    expect($ids)->not->toContain($externalId);
})->with([
    // Tomaszów can only be searched by county on Otodom, so houses ride along.
    'a house' => ['house', '900001'],
    // "Zapytaj o cenę" — nothing to compare against the budget.
    'a hidden price' => ['hidden price', '900002'],
    // 125 000 EUR read as PLN would look like a bargain inside our budget.
    'a price in euro' => ['euro price', '900003'],
    // A village sharing the county page with Tomaszów.
    'a city we do not want' => ['wrong city', '900004'],
]);

it('reports nothing rather than crashing when the payload is absent', function (): void {
    $page = $this->parser->parse('<html><body>Akceptuj cookies</body></html>');

    expect($page->listings)->toBe([])
        ->and($page->totalPages)->toBe(0);
});
