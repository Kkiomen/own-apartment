<?php

declare(strict_types=1);

use App\Domain\Listing\Availability;
use App\Domain\Listing\Port\OfferInspector;
use App\Domain\Listing\SourceReference;
use App\Domain\Listing\ValueObject\Portal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

function offer(string $url = 'https://www.olx.pl/d/oferta/x.html'): SourceReference
{
    return new SourceReference(
        portal: Portal::Olx,
        externalId: 'olx-1',
        url: $url,
        title: 'Mieszkanie 60 m², 3 pokoje',
        firstSeenAt: new DateTimeImmutable('2026-07-01 09:00:00'),
        lastSeenAt: new DateTimeImmutable('2026-08-01 09:00:00'),
    );
}

beforeEach(function (): void {
    // The politeness pause is real behaviour and is tested elsewhere; here it
    // would only cost wall-clock.
    Sleep::fake();
});

it('reads a withdrawn advert from the status the portal answers', function (int $status): void {
    // OLX and Otodom both answer 410 for an advert that has been taken down —
    // this is the whole point of verifying, since a search sweep can only ever
    // say "not seen".
    Http::fake(['*' => Http::response('', $status)]);

    expect(app(OfferInspector::class)->inspect(offer())->availability)->toBe(Availability::Gone);
})->with([410, 404]);

it('trusts the status even when the portal still renders the whole advert', function (): void {
    // Otodom's archived pages come back as a full 355 kB offer page — with a
    // 410 on it. Reading the body first would call this one available.
    Http::fake(['*' => Http::response('<html><body>599 044 zł · 51,31 m² · 3 pokoje</body></html>', 410)]);

    expect(app(OfferInspector::class)->inspect(offer())->availability)->toBe(Availability::Gone);
});

it('reads an archived advert from its page title', function (): void {
    // Backup for a portal that stops answering 410. The wording is Otodom's own,
    // taken from a live archived offer.
    Http::fake(['*' => Http::response(
        '<html><head><title>Archiwalne: 3 pokoje, mieszkanie na sprzedaż - Wrocław, Fabryczna - 67911901</title></head><body>Mieszkanie</body></html>',
    )]);

    expect(app(OfferInspector::class)->inspect(offer())->availability)->toBe(Availability::Gone);
});

it('does not read an ordinary offer title as an archived one', function (): void {
    Http::fake(['*' => Http::response(
        '<html><head><title>3 pokoje, mieszkanie na sprzedaż - Wrocław, Fabryczna - 67911901</title></head><body>Mieszkanie</body></html>',
    )]);

    expect(app(OfferInspector::class)->inspect(offer())->availability)->toBe(Availability::Available);
});

it('leaves an advert that still renders alone', function (): void {
    Http::fake(['*' => Http::response('<html><body><h1>Mieszkanie 60 m², 3 pokoje</h1><p>599 000 zł</p></body></html>')]);

    expect(app(OfferInspector::class)->inspect(offer())->availability)->toBe(Availability::Available);
});

it('reads an expired advert served as an ordinary page', function (): void {
    // Not every portal answers with a status code — some return 200 and say it
    // in words.
    Http::fake(['*' => Http::response(
        '<html><body><div class="msg">To ogłoszenie nie jest już <b>dostępne</b></div></body></html>',
    )]);

    expect(app(OfferInspector::class)->inspect(offer())->availability)->toBe(Availability::Gone);
});

it('reports being blocked as its own answer, not as a missing advert', function (int $status): void {
    // The difference that matters: 403 says something about us, 410 says
    // something about the flat. Confusing the two retires the whole list the
    // first time a portal rate-limits the VPS.
    Http::fake(['*' => Http::response('', $status)]);

    expect(app(OfferInspector::class)->inspect(offer())->availability)->toBe(Availability::Refused);
})->with([403, 429, 503]);

it('reports a server error as unknown rather than gone', function (): void {
    Http::fake(['*' => Http::response('', 500)]);

    expect(app(OfferInspector::class)->inspect(offer())->availability)->toBe(Availability::Unreachable);
});

it('asks for the advert at its own url', function (): void {
    Http::fake(['*' => Http::response('<html></html>')]);

    app(OfferInspector::class)->inspect(offer('https://www.otodom.pl/pl/oferta/abc'));

    Http::assertSent(fn ($request): bool => $request->url() === 'https://www.otodom.pl/pl/oferta/abc');
});
