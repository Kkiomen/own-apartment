<?php

declare(strict_types=1);

use App\Application\Verification\VerificationReport;
use App\Application\Verification\VerifyListings;
use App\Domain\Listing\Availability;
use App\Domain\Listing\Listing;
use App\Domain\Listing\SourceReference;
use App\Domain\Listing\ValueObject\Portal;
use Psr\Log\NullLogger;
use Tests\Support\FakeOfferInspector;
use Tests\Support\FrozenClock;
use Tests\Support\InMemoryListingRepository;
use Tests\Support\InMemoryListingVerification;
use Tests\Support\ListingDataFactory;

function advert(Portal $portal, string $externalId): SourceReference
{
    return new SourceReference(
        portal: $portal,
        externalId: $externalId,
        url: "https://portal.test/{$externalId}",
        title: 'Mieszkanie 56 m², 3 pokoje',
        firstSeenAt: new DateTimeImmutable('2026-07-01 09:00:00'),
        lastSeenAt: new DateTimeImmutable('2026-08-01 09:00:00'),
    );
}

beforeEach(function (): void {
    $this->queue = new InMemoryListingVerification;
    $this->portals = new FakeOfferInspector;
    $this->listings = new InMemoryListingRepository;
    $this->clock = FrozenClock::at('2026-08-11 12:00:00');

    $this->run = fn (int $maxPerRun = 10): VerificationReport => (new VerifyListings(
        verification: $this->queue,
        offers: $this->portals,
        listings: $this->listings,
        maintenance: $this->queue,
        clock: $this->clock,
        logger: new NullLogger,
        recheckAfterHours: 24,
        maxPerRun: $maxPerRun,
    ))();
});

it('retires an advert the portal says is gone', function (): void {
    $this->queue->add(advert(Portal::Olx, 'olx-gone'));
    $this->portals->answer('olx-gone', Availability::Gone);

    $report = ($this->run)();

    expect($report->gone())->toBe(1)
        ->and($this->queue->isActive(Portal::Olx, 'olx-gone'))->toBeFalse();
});

it('leaves an advert that is still up alone', function (): void {
    $this->queue->add(advert(Portal::Olx, 'olx-live'));

    $report = ($this->run)();

    expect($report->available())->toBe(1)
        ->and($this->queue->isActive(Portal::Olx, 'olx-live'))->toBeTrue()
        ->and($this->queue->checkedAt(Portal::Olx, 'olx-live'))->toEqual($this->clock->now());
});

it('never retires an advert on a page it could not read', function (): void {
    // A timeout is a fact about our network, not about the flat. Treating it as
    // "sold" would empty the list the first time the VPS has a bad minute.
    $this->queue->add(advert(Portal::Otodom, 'oto-1'));
    $this->portals->answer('oto-1', Availability::Unreachable);

    $report = ($this->run)();

    expect($report->unreachable())->toBe(1)
        ->and($report->gone())->toBe(0)
        ->and($this->queue->isActive(Portal::Otodom, 'oto-1'))->toBeTrue();
});

it('stamps an unreachable advert as checked so it cannot block the queue', function (): void {
    $this->queue->add(advert(Portal::Otodom, 'oto-broken'));
    $this->portals->answer('oto-broken', Availability::Unreachable);

    ($this->run)();

    expect($this->queue->checkedAt(Portal::Otodom, 'oto-broken'))->toEqual($this->clock->now());
});

it('stops asking a portal that has just refused us', function (): void {
    $this->queue->add(
        advert(Portal::Olx, 'olx-1'),
        advert(Portal::Olx, 'olx-2'),
        advert(Portal::Otodom, 'oto-1'),
    );
    $this->portals->answer('olx-1', Availability::Refused);

    $report = ($this->run)();

    // olx-2 is never knocked on; the other portal is unaffected.
    expect($this->portals->asked)->toBe(['olx-1', 'oto-1'])
        ->and($report->skipped())->toBe(2)
        ->and($report->refusedPortals())->toBe(['olx']);
});

it('keeps a refused advert queued rather than marking it checked', function (): void {
    $this->queue->add(advert(Portal::Olx, 'olx-1'));
    $this->portals->answer('olx-1', Availability::Refused);

    ($this->run)();

    expect($this->queue->checkedAt(Portal::Olx, 'olx-1'))->toBeNull()
        ->and($this->queue->isActive(Portal::Olx, 'olx-1'))->toBeTrue();
});

it('checks no more adverts per run than it is allowed to', function (): void {
    $this->queue->add(
        advert(Portal::Olx, 'olx-1'),
        advert(Portal::Olx, 'olx-2'),
        advert(Portal::Olx, 'olx-3'),
    );

    $report = ($this->run)(maxPerRun: 2);

    expect($report->checked())->toBe(2);
});

it('retires the flat once its last advert is gone', function (): void {
    $this->queue->add(advert(Portal::Olx, 'olx-gone'));
    $this->portals->answer('olx-gone', Availability::Gone);
    $this->queue->listingsRetired = 1;

    expect(($this->run)()->retiredListings())->toBe(1);
});

it('does not go looking for retired flats when nothing went', function (): void {
    $this->queue->add(advert(Portal::Olx, 'olx-live'));
    $this->queue->listingsRetired = 7;

    expect(($this->run)()->retiredListings())->toBe(0);
});

it('takes the full gallery off the offer page', function (): void {
    // The gap this closes: a search page carries the first three photos of an
    // advert that has thirteen, and that is all a scan can ever store.
    $listing = Listing::fromFirstSighting(
        ListingDataFactory::matching(externalId: 'olx-1'),
        new DateTimeImmutable('2026-08-01 09:00:00'),
    );
    $listing->refreshGallery(['a.jpg', 'b.jpg', 'c.jpg']);
    $this->listings->save($listing);

    $this->queue->add(advert(Portal::Olx, 'olx-1'));
    $this->portals->gallery('olx-1', ['a.jpg', 'b.jpg', 'c.jpg', 'd.jpg', 'e.jpg']);

    $report = ($this->run)();

    expect($report->galleriesFilled())->toBe(1)
        ->and($this->listings->all()[0]->images())->toHaveCount(5);
});

it('does not count a gallery that told us nothing new', function (): void {
    $listing = Listing::fromFirstSighting(
        ListingDataFactory::matching(externalId: 'olx-1'),
        new DateTimeImmutable('2026-08-01 09:00:00'),
    );
    $listing->refreshGallery(['a.jpg', 'b.jpg', 'c.jpg']);
    $this->listings->save($listing);

    $this->queue->add(advert(Portal::Olx, 'olx-1'));
    $this->portals->gallery('olx-1', ['a.jpg', 'b.jpg', 'c.jpg']);

    expect(($this->run)()->galleriesFilled())->toBe(0);
});

it('keeps the photos it has when the offer page yields none', function (): void {
    // A portal reader that breaks must cost us nothing. Silence is not an
    // instruction to throw the gallery away.
    $listing = Listing::fromFirstSighting(
        ListingDataFactory::matching(externalId: 'olx-1'),
        new DateTimeImmutable('2026-08-01 09:00:00'),
    );
    $listing->refreshGallery(['a.jpg', 'b.jpg', 'c.jpg']);
    $this->listings->save($listing);

    $this->queue->add(advert(Portal::Olx, 'olx-1'));

    ($this->run)();

    expect($this->listings->all()[0]->images())->toHaveCount(3);
});

it('re-checks an advert only once its recheck window has passed', function (): void {
    $this->queue->add(advert(Portal::Olx, 'olx-1'));

    ($this->run)();

    // Same day: nothing due.
    expect(($this->run)()->checked())->toBe(0);

    $this->clock->advanceTo('2026-08-12 13:00:00');

    expect(($this->run)()->checked())->toBe(1);
});
