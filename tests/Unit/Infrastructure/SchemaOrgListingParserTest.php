<?php

declare(strict_types=1);

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Infrastructure\Scraping\Support\AccommodationOfferMapper;
use App\Infrastructure\Scraping\Support\LdJsonExtractor;
use App\Infrastructure\Scraping\Support\SchemaOrgListingParser;
use Psr\Log\NullLogger;

/*
 * Gratka, Morizon and Nieruchomości-online publish the same schema.org shape,
 * so one parser serves all three and one test proves it for all three. The
 * fixtures are captured from the live sites with three synthetic offers added
 * for the cases that silently corrupt a listings list.
 */

$portals = [
    'gratka' => [Portal::Gratka, 'gratka', ['offers', 'offers']],
    'morizon' => [Portal::Morizon, 'morizon', ['offers', 'offers']],
    'nieruchomosci-online' => [Portal::NieruchomosciOnline, 'nieruchomosci-online', ['mainEntity', 'offers', 0, 'offers']],
];

beforeEach(function (): void {
    $this->parser = new SchemaOrgListingParser(
        new LdJsonExtractor,
        new AccommodationOfferMapper,
        new NullLogger,
    );

    $this->fixture = fn (string $dir): string => file_get_contents(__DIR__."/../../Fixtures/{$dir}/search-wroclaw-page-1.html");
});

it('reads listings from structured data', function (Portal $portal, string $dir, array $path): void {
    $page = $this->parser->parse($portal, ($this->fixture)($dir), $path);

    expect($page->listings)->toHaveCount(3)
        ->and($page->skipped)->toBe(3)
        ->and($page->listings[0]->portal)->toBe($portal);
})->with($portals);

it('refuses a price that is not in PLN', function (Portal $portal, string $dir, array $path): void {
    $ids = array_map(static fn ($l): string => $l->externalId, $this->parser->parse($portal, ($this->fixture)($dir), $path)->listings);

    expect($ids)->not->toContain('99000001');
})->with($portals);

it('skips an offer with no room count', function (Portal $portal, string $dir, array $path): void {
    // Nieruchomości-online omits numberOfRooms on roughly a fifth of its ads.
    $ids = array_map(static fn ($l): string => $l->externalId, $this->parser->parse($portal, ($this->fixture)($dir), $path)->listings);

    expect($ids)->not->toContain('99000002');
})->with($portals);

it('drops cities we do not want', function (Portal $portal, string $dir, array $path): void {
    $ids = array_map(static fn ($l): string => $l->externalId, $this->parser->parse($portal, ($this->fixture)($dir), $path)->listings);

    expect($ids)->not->toContain('99000003');
})->with($portals);

it('resolves every parsed listing to a city we want', function (Portal $portal, string $dir, array $path): void {
    foreach ($this->parser->parse($portal, ($this->fixture)($dir), $path)->listings as $listing) {
        expect($listing->city())->toBe(City::Wroclaw);
    }
})->with($portals);

it('falls back to the URL when the portal prints a district as the city', function (): void {
    // Gratka reports addressLocality as "Różanka" — a Wrocław district. Taken
    // at face value that is an unknown city and every listing is dropped, so
    // the city is recovered from the URL slug instead.
    $page = $this->parser->parse(Portal::Gratka, ($this->fixture)('gratka'), ['offers', 'offers']);

    expect($page->listings)->not->toBeEmpty();

    foreach ($page->listings as $listing) {
        expect($listing->city())->toBe(City::Wroclaw);
    }
});

it('reports nothing rather than crashing when the structured data is absent', function (): void {
    $page = $this->parser->parse(Portal::Gratka, '<html><body>nic tu nie ma</body></html>', ['offers', 'offers']);

    expect($page->listings)->toBe([])
        ->and($page->totalPages)->toBe(0);
});
