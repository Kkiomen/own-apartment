<?php

declare(strict_types=1);

use App\Infrastructure\Scraping\Olx\OlxOfferGallery;
use App\Infrastructure\Scraping\Otodom\OtodomOfferGallery;
use App\Infrastructure\Scraping\Support\EmbeddedJsonExtractor;
use App\Infrastructure\Scraping\Support\LdJsonExtractor;
use App\Infrastructure\Scraping\Support\LdJsonOfferGallery;

function offerPage(string $portal): string
{
    return (string) file_get_contents(__DIR__."/../../Fixtures/{$portal}/offer-page.html");
}

function olxGallery(): OlxOfferGallery
{
    return new OlxOfferGallery(new LdJsonOfferGallery(new LdJsonExtractor));
}

it('reads every photo off an OLX offer page', function (): void {
    // The whole point of the second request: the same advert's search entry
    // carries 3 photos, its own page carries 13.
    $images = olxGallery()->imagesFrom(offerPage('olx'));

    expect($images)->toHaveCount(13)
        ->and($images[0])->toContain('3o4hebw4ho7g3-PL')
        ->and($images[12])->toContain('h76uq6w5bxl33-PL');
});

it('falls back to the structured data when OLX changes its markup', function (): void {
    // ld+json holds 5 of the 13. Fewer than the gallery, still more than the 3
    // a search page gives — so a markup change costs photos, never all of them.
    $withoutSlides = preg_replace('/data-testid="ad-photo"/', 'data-testid="something-else"', offerPage('olx'));

    expect(olxGallery()->imagesFrom((string) $withoutSlides))->toHaveCount(5);
});

it('finds nothing on a page that is not an offer', function (): void {
    expect(olxGallery()->imagesFrom('<html><body>Nie znaleziono</body></html>'))->toBe([]);
});

it('reads every photo off an Otodom offer page', function (): void {
    // 20 photos in __NEXT_DATA__, against the 3 its search payload carries.
    $images = (new OtodomOfferGallery(new EmbeddedJsonExtractor))->imagesFrom(offerPage('otodom'));

    expect($images)->toHaveCount(20)
        ->and($images[0])->toStartWith('https://')
        // The size the modal wants, not the 184x138 thumbnail.
        ->and($images[0])->toContain('image;s=2048x1536');
});

it('says nothing rather than guessing when the payload is missing', function (): void {
    $gallery = new OtodomOfferGallery(new EmbeddedJsonExtractor);

    expect($gallery->imagesFrom('<html><body>Ogłoszenie</body></html>'))->toBe([])
        ->and($gallery->imagesFrom('<script id="__NEXT_DATA__" type="application/json">{"props":{}}</script>'))->toBe([]);
});

it('reads a schema.org gallery for the portals that publish one', function (): void {
    // Unverified against a live Gratka/Morizon/Nieruchomości-online offer page:
    // this is the portable reader, and its failure mode is finding nothing.
    $gallery = new LdJsonOfferGallery(new LdJsonExtractor);

    $html = '<script type="application/ld+json">'
        .'{"@type":"Product","image":["https://portal.test/1.jpg","https://portal.test/2.jpg"]}'
        .'</script>';

    expect($gallery->imagesFrom($html))->toBe(['https://portal.test/1.jpg', 'https://portal.test/2.jpg']);
});

it('reads a single photo published as a bare string', function (): void {
    $gallery = new LdJsonOfferGallery(new LdJsonExtractor);

    $html = '<script type="application/ld+json">{"@type":"Product","image":"https://portal.test/1.jpg"}</script>';

    expect($gallery->imagesFrom($html))->toBe(['https://portal.test/1.jpg']);
});
