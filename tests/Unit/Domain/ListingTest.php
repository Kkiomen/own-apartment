<?php

declare(strict_types=1);

use App\Domain\Listing\Listing;
use App\Domain\Listing\ValueObject\Portal;
use Tests\Support\ListingDataFactory;

$now = new DateTimeImmutable('2026-08-01 10:00:00');
$later = new DateTimeImmutable('2026-08-03 10:00:00');

it('reports no change when the same price is seen again', function () use ($now, $later): void {
    // This is what stops the price history table growing by thousands of
    // meaningless rows every day.
    $listing = Listing::fromFirstSighting(ListingDataFactory::matching(priceZloty: 549_000), $now);

    $change = $listing->registerSighting(ListingDataFactory::matching(priceZloty: 549_000), $later);

    expect($change)->toBeNull()
        ->and($listing->lastSeenAt())->toEqual($later)
        ->and($listing->firstSeenAt())->toEqual($now);
});

it('reports a price drop', function () use ($now, $later): void {
    $listing = Listing::fromFirstSighting(ListingDataFactory::matching(priceZloty: 549_000), $now);

    $change = $listing->registerSighting(ListingDataFactory::matching(priceZloty: 520_000), $later);

    expect($change)->not->toBeNull()
        ->and($change->isDrop())->toBeTrue()
        ->and($change->percentageChange())->toBe(-5.3)
        ->and($listing->price()->zloty())->toBe(520_000.0);
});

it('reports a price increase without calling it a drop', function () use ($now, $later): void {
    $listing = Listing::fromFirstSighting(ListingDataFactory::matching(priceZloty: 500_000), $now);

    $change = $listing->registerSighting(ListingDataFactory::matching(priceZloty: 530_000), $later);

    expect($change->isDrop())->toBeFalse()
        ->and($change->percentageChange())->toBe(6.0);
});

it('collects a second portal as another source of the same flat', function () use ($now, $later): void {
    $listing = Listing::fromFirstSighting(
        ListingDataFactory::matching(portal: Portal::Olx, externalId: 'olx-1'),
        $now,
    );

    $listing->registerSighting(
        ListingDataFactory::matching(portal: Portal::Otodom, externalId: 'oto-9'),
        $later,
    );

    expect($listing->sources())->toHaveCount(2)
        ->and($listing->portals())->toBe([Portal::Olx, Portal::Otodom]);
});

it('does not duplicate a source when the same advert is seen twice', function () use ($now, $later): void {
    $listing = Listing::fromFirstSighting(
        ListingDataFactory::matching(portal: Portal::Olx, externalId: 'olx-1'),
        $now,
    );

    $listing->registerSighting(
        ListingDataFactory::matching(portal: Portal::Olx, externalId: 'olx-1'),
        $later,
    );

    expect($listing->sources())->toHaveCount(1)
        ->and($listing->sources()[0]->firstSeenAt)->toEqual($now)
        ->and($listing->sources()[0]->lastSeenAt)->toEqual($later);
});

it('moves its fingerprint when the price moves', function () use ($now, $later): void {
    // The fingerprint contains the price, so it has to be recomputed — otherwise
    // a cross-portal match would be tested against a stale key and the same
    // flat would be stored twice.
    $listing = Listing::fromFirstSighting(ListingDataFactory::matching(priceZloty: 549_000), $now);
    $before = $listing->fingerprint();

    $listing->registerSighting(ListingDataFactory::matching(priceZloty: 520_000), $later);

    expect($listing->fingerprint()->equals($before))->toBeFalse();
});

it('keeps the newest title', function () use ($now, $later): void {
    $listing = Listing::fromFirstSighting(ListingDataFactory::matching(title: 'Stary tytuł'), $now);

    $listing->registerSighting(ListingDataFactory::matching(title: 'Nowy tytuł, po obniżce'), $later);

    expect($listing->title())->toBe('Nowy tytuł, po obniżce');
});

it('does not let a portal with one photo erase a gallery', function () use ($now, $later): void {
    // Where the "only one photo" bug came from: the same flat is found on OLX
    // and on a schema.org portal, and those publish exactly one image per
    // result. Scanned last, a single photo used to overwrite all of them.
    $listing = Listing::fromFirstSighting(
        ListingDataFactory::matching(images: ['a.jpg', 'b.jpg', 'c.jpg']),
        $now,
    );

    $listing->registerSighting(
        ListingDataFactory::matching(portal: Portal::NieruchomosciOnline, externalId: 'nol-1', images: ['only.jpg']),
        $later,
    );

    expect($listing->images())->toBe(['a.jpg', 'b.jpg', 'c.jpg']);
});

it('takes a richer gallery from whichever portal has one', function () use ($now, $later): void {
    $listing = Listing::fromFirstSighting(ListingDataFactory::matching(images: ['a.jpg']), $now);

    $listing->registerSighting(
        ListingDataFactory::matching(portal: Portal::Otodom, externalId: 'oto-1', images: ['a.jpg', 'b.jpg', 'c.jpg']),
        $later,
    );

    expect($listing->images())->toHaveCount(3);
});

it('refuses a gallery poorer than the one it already has', function () use ($now): void {
    // The offer page is the good source, but a reader that has stopped finding
    // photos must not be able to empty a listing.
    $listing = Listing::fromFirstSighting(
        ListingDataFactory::matching(images: ['a.jpg', 'b.jpg', 'c.jpg']),
        $now,
    );

    expect($listing->refreshGallery(['x.jpg']))->toBeFalse()
        ->and($listing->refreshGallery([]))->toBeFalse()
        ->and($listing->images())->toBe(['a.jpg', 'b.jpg', 'c.jpg'])
        ->and($listing->refreshGallery(['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg']))->toBeTrue();
});
