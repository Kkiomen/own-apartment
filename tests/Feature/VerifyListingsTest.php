<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\Eloquent\Models\ListingRecord;
use App\Infrastructure\Persistence\Eloquent\Models\ListingSourceRecord;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;

function advertisedOn(string $portal, string $externalId, int $listingId = 1, ?string $verifiedAt = null): void
{
    ListingRecord::query()->updateOrCreate(['id' => $listingId], [
        'fingerprint' => "fp-{$listingId}",
        'city' => 'wroclaw',
        'area_m2' => 56.0,
        'rooms' => 3,
        'price_grosze' => 54_900_000,
        'title' => 'Mieszkanie',
        'first_seen_at' => '2026-08-01 09:00:00',
        'last_seen_at' => '2026-08-11 09:00:00',
        'is_active' => true,
    ]);

    ListingSourceRecord::query()->create([
        'listing_id' => $listingId,
        'portal' => $portal,
        'external_id' => $externalId,
        'url' => "https://{$portal}.test/{$externalId}",
        'title' => 'Mieszkanie',
        'first_seen_at' => '2026-08-01 09:00:00',
        'last_seen_at' => '2026-08-11 09:00:00',
        'last_verified_at' => $verifiedAt,
        'is_active' => true,
    ]);
}

beforeEach(function (): void {
    Sleep::fake();
});

it('takes a withdrawn flat off the list the day the portal drops it', function (): void {
    // The gap this closes: without it the flat sits on the browse screen for a
    // fortnight, because a search sweep can only say "I did not see it".
    advertisedOn('olx', 'olx-gone');
    Http::fake(['*' => Http::response('', 410)]);

    $this->artisan('listings:verify')->assertSuccessful();

    $this->assertDatabaseHas('listing_sources', ['external_id' => 'olx-gone', 'is_active' => false]);
    $this->assertDatabaseHas('listings', ['id' => 1, 'is_active' => false]);

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('listings', 0),
    );
});

it('keeps a flat whose other portal still advertises it', function (): void {
    advertisedOn('olx', 'olx-gone');
    advertisedOn('otodom', 'oto-live');

    Http::fake([
        'https://olx.test/*' => Http::response('', 410),
        'https://otodom.test/*' => Http::response('<html>Mieszkanie na sprzedaż</html>'),
    ]);

    $this->artisan('listings:verify')->assertSuccessful();

    $this->assertDatabaseHas('listings', ['id' => 1, 'is_active' => true]);

    // The dead advert's button goes with it — a link to a withdrawn offer reads
    // as the whole listing being dead.
    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page
            ->has('listings', 1)
            ->has('listings.0.sources', 1)
            ->where('listings.0.sources.0.portal', 'otodom'),
    );
});

it('leaves a listing alone when the portal cannot be read', function (): void {
    advertisedOn('olx', 'olx-1');
    Http::fake(['*' => Http::response('', 500)]);

    $this->artisan('listings:verify')->assertSuccessful();

    $this->assertDatabaseHas('listing_sources', ['external_id' => 'olx-1', 'is_active' => true]);
    $this->assertDatabaseHas('listings', ['id' => 1, 'is_active' => true]);
});

it('stops verifying a portal that blocks us, and says so', function (): void {
    advertisedOn('olx', 'olx-1');
    advertisedOn('olx', 'olx-2', listingId: 2);
    Http::fake(['*' => Http::response('', 429)]);

    // Never a clean run: those adverts were not actually checked.
    $this->artisan('listings:verify')->assertFailed();

    Http::assertSentCount(1);
    $this->assertDatabaseHas('listing_sources', ['external_id' => 'olx-2', 'last_verified_at' => null]);
});

it('checks the longest-unchecked adverts first', function (): void {
    advertisedOn('olx', 'checked-today', listingId: 1, verifiedAt: now()->subHours(2)->toDateTimeString());
    advertisedOn('olx', 'checked-long-ago', listingId: 2, verifiedAt: now()->subDays(9)->toDateTimeString());
    advertisedOn('olx', 'never-checked', listingId: 3);

    Http::fake(['*' => Http::response('<html>Mieszkanie na sprzedaż</html>')]);

    $this->artisan('listings:verify --limit=1')->assertSuccessful();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'never-checked'));
    Http::assertSentCount(1);
});

it('does not re-check an advert inside its recheck window', function (): void {
    advertisedOn('olx', 'fresh', verifiedAt: now()->subHour()->toDateTimeString());
    Http::fake();

    $this->artisan('listings:verify')
        ->expectsOutputToContain('Nothing due for verification.')
        ->assertSuccessful();

    Http::assertNothingSent();
});

it('fills in the photos a search page could not carry', function (): void {
    // The bug this closes, end to end: OLX search results hold 3 photos of an
    // advert that has 13, so the modal only ever had 3 to show.
    advertisedOn('olx', 'olx-1');
    ListingRecord::query()->whereKey(1)->update([
        'images' => json_encode(['https://olx.test/a.jpg', 'https://olx.test/b.jpg', 'https://olx.test/c.jpg']),
    ]);

    Http::fake(['*' => Http::response(file_get_contents(base_path('tests/Fixtures/olx/offer-page.html')))]);

    $this->artisan('listings:verify')->assertSuccessful();

    $this->get('/')->assertOk()->assertInertia(
        fn (AssertableInertia $page) => $page->has('listings.0.images', 13),
    );
});

it('never verifies an advert that is already retired', function (): void {
    advertisedOn('olx', 'olx-retired');
    ListingSourceRecord::query()->where('external_id', 'olx-retired')->update(['is_active' => false]);

    Http::fake();

    $this->artisan('listings:verify')->assertSuccessful();

    Http::assertNothingSent();
});
