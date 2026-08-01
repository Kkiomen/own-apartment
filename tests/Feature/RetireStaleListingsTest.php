<?php

declare(strict_types=1);

use App\Domain\Listing\Port\ListingMaintenance;
use App\Domain\Listing\ValueObject\Portal;
use App\Infrastructure\Persistence\Eloquent\Models\ListingRecord;
use App\Infrastructure\Persistence\Eloquent\Models\ListingSourceRecord;
use Inertia\Testing\AssertableInertia;

function listingSeenAt(string $portal, string $externalId, string $seenAt, int $listingId = 1): void
{
    ListingRecord::query()->updateOrCreate(['id' => $listingId], [
        'fingerprint' => "fp-{$listingId}",
        'city' => 'wroclaw',
        'area_m2' => 56.0,
        'rooms' => 3,
        'price_grosze' => 54_900_000,
        'title' => 'Mieszkanie',
        'first_seen_at' => '2026-07-01 09:00:00',
        'last_seen_at' => $seenAt,
        'is_active' => true,
    ]);

    ListingSourceRecord::query()->create([
        'listing_id' => $listingId,
        'portal' => $portal,
        'external_id' => $externalId,
        'url' => "https://example.test/{$externalId}",
        'title' => 'Mieszkanie',
        'first_seen_at' => '2026-07-01 09:00:00',
        'last_seen_at' => $seenAt,
        'is_active' => true,
    ]);
}

it('retires an advert the portal stopped showing', function (): void {
    listingSeenAt('olx', 'olx-old', '2026-07-01 09:00:00');

    $retired = app(ListingMaintenance::class)
        ->deactivateSourcesNotSeenSince(Portal::Olx, new DateTimeImmutable('2026-07-20 09:00:00'));

    expect($retired)->toBe(1);
    $this->assertDatabaseHas('listing_sources', ['external_id' => 'olx-old', 'is_active' => false]);
});

it('leaves adverts seen recently alone', function (): void {
    listingSeenAt('olx', 'olx-fresh', '2026-07-25 09:00:00');

    $retired = app(ListingMaintenance::class)
        ->deactivateSourcesNotSeenSince(Portal::Olx, new DateTimeImmutable('2026-07-20 09:00:00'));

    expect($retired)->toBe(0);
    $this->assertDatabaseHas('listing_sources', ['external_id' => 'olx-fresh', 'is_active' => true]);
});

it('only touches the portal it was asked about', function (): void {
    // Scoping matters: a portal that is down must not have its listings retired
    // by another portal's successful scan.
    listingSeenAt('olx', 'olx-old', '2026-07-01 09:00:00');
    listingSeenAt('otodom', 'oto-old', '2026-07-01 09:00:00', listingId: 2);

    app(ListingMaintenance::class)
        ->deactivateSourcesNotSeenSince(Portal::Olx, new DateTimeImmutable('2026-07-20 09:00:00'));

    $this->assertDatabaseHas('listing_sources', ['external_id' => 'olx-old', 'is_active' => false]);
    $this->assertDatabaseHas('listing_sources', ['external_id' => 'oto-old', 'is_active' => true]);
});

it('retires a flat once no portal advertises it', function (): void {
    listingSeenAt('olx', 'olx-old', '2026-07-01 09:00:00');
    app(ListingMaintenance::class)->deactivateSourcesNotSeenSince(Portal::Olx, new DateTimeImmutable('2026-07-20 09:00:00'));

    $retired = app(ListingMaintenance::class)->deactivateListingsWithoutActiveSources();

    expect($retired)->toBe(1);
    $this->assertDatabaseHas('listings', ['id' => 1, 'is_active' => false]);
});

it('keeps a flat alive while any portal still advertises it', function (): void {
    // The same flat on OLX and Otodom: OLX pulls it, Otodom still has it. It is
    // still for sale, so it stays on our list.
    listingSeenAt('olx', 'olx-old', '2026-07-01 09:00:00');
    listingSeenAt('otodom', 'oto-fresh', '2026-07-25 09:00:00');

    app(ListingMaintenance::class)->deactivateSourcesNotSeenSince(Portal::Olx, new DateTimeImmutable('2026-07-20 09:00:00'));
    $retired = app(ListingMaintenance::class)->deactivateListingsWithoutActiveSources();

    expect($retired)->toBe(0);
    $this->assertDatabaseHas('listings', ['id' => 1, 'is_active' => true]);
});

it('hides retired flats from the browse screen', function (): void {
    listingSeenAt('olx', 'olx-old', '2026-07-01 09:00:00');
    app(ListingMaintenance::class)->deactivateSourcesNotSeenSince(Portal::Olx, new DateTimeImmutable('2026-07-20 09:00:00'));
    app(ListingMaintenance::class)->deactivateListingsWithoutActiveSources();

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('listings', 0)->where('stats.total', 0),
    );
});
