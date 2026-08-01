<?php

declare(strict_types=1);

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Infrastructure\Scraping\Olx\OlxListingParser;
use App\Infrastructure\Scraping\Support\EmbeddedJsonExtractor;
use Psr\Log\NullLogger;

/*
 * Parsed from a fixture captured from a real OLX search page — never from the
 * network. The fixture keeps the exact JSON shape OLX serves, trimmed to five
 * ads covering the cases that actually break parsers.
 */

beforeEach(function (): void {
    $this->parser = new OlxListingParser(new EmbeddedJsonExtractor, new NullLogger);
    $this->html = file_get_contents(__DIR__.'/../../Fixtures/olx/search-wroclaw-page-1.html');
});

it('reads the listings OLX embedded in the page', function (): void {
    $page = $this->parser->parse($this->html);

    expect($page->listings)->toHaveCount(3)
        ->and($page->totalPages)->toBe(2);
});

it('maps every field we depend on', function (): void {
    $listing = $this->parser->parse($this->html)->listings[0];

    expect($listing->portal)->toBe(Portal::Olx)
        ->and($listing->externalId)->toBe('1085707112')
        ->and($listing->city())->toBe(City::Wroclaw)
        ->and($listing->district)->toBe('Krzyki')
        ->and($listing->area()->squareMeters)->toBe(51.0)
        ->and($listing->rooms())->toBe(2)
        ->and($listing->price()->zloty())->toBe(579_000.0)
        ->and($listing->floor)->toBe(2)
        ->and($listing->url)->toStartWith('https://www.olx.pl/')
        ->and($listing->publishedAt)->not->toBeNull();
});

it('keeps the description as plain text for the details view', function (): void {
    $listing = $this->parser->parse($this->html)->listings[0];

    expect($listing->description)->not->toBeNull()
        // OLX serves it as an HTML fragment; nothing renders markup here.
        ->and($listing->description)->not->toContain('<p>')
        ->and($listing->description)->not->toContain('&');
});

it('collects a gallery, not just one photo', function (): void {
    $listing = $this->parser->parse($this->html)->listings[0];

    expect($listing->images)->toHaveCount(3)
        ->and($listing->images[0])->toStartWith('https://');
});

it('translates OLX word-based room counts into numbers', function (): void {
    // OLX says "two"/"three"/"four", not 2/3/4.
    $rooms = array_map(
        static fn ($listing): int => $listing->rooms(),
        $this->parser->parse($this->html)->listings,
    );

    expect($rooms)->toBe([2, 3, 4]);
});

it('skips an ad missing the fields we cannot invent', function (): void {
    // No area and no room count. Guessing here would put a flat we know
    // nothing about in front of us as if it matched.
    $ids = array_map(static fn ($l): string => $l->externalId, $this->parser->parse($this->html)->listings);

    expect($ids)->not->toContain('999000111');
});

it('refuses a price that is not in PLN', function (): void {
    // 130 000 EUR read as PLN lands inside our 300-600k budget and looks like
    // the bargain of the year. It is not.
    $ids = array_map(static fn ($l): string => $l->externalId, $this->parser->parse($this->html)->listings);

    expect($ids)->not->toContain('999000222');
});

it('counts what it skipped so a markup change is visible', function (): void {
    expect($this->parser->parse($this->html)->skipped)->toBe(2);
});

it('reports nothing rather than crashing when served a consent wall', function (): void {
    $page = $this->parser->parse('<html><body>Akceptuj cookies</body></html>');

    expect($page->listings)->toBe([])
        ->and($page->totalPages)->toBe(0);
});
