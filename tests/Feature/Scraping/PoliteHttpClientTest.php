<?php

declare(strict_types=1);

use App\Infrastructure\Scraping\Support\PageNotFound;
use App\Infrastructure\Scraping\Support\PoliteHttpClient;
use App\Infrastructure\Scraping\Support\PortalRefused;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

beforeEach(function (): void {
    Sleep::fake();
    Http::preventStrayRequests();

    $this->client = fn (int $capSeconds = 30): PoliteHttpClient => new PoliteHttpClient(
        http: app(Factory::class),
        delayMs: 1000,
        timeoutSeconds: 20,
        userAgent: 'test-agent',
        retryAfterCapSeconds: $capSeconds,
    );
});

it('pauses before every request, near the configured delay', function (): void {
    Http::fake(['*' => Http::response('<html></html>')]);

    ($this->client)()->get('https://portal.test/page-1');

    // Jittered on purpose, so the assertion is a band rather than a value.
    Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds >= 750
        && $duration->totalMilliseconds <= 1250, 1);
});

it('does not send two requests exactly the same distance apart', function (): void {
    Http::fake(['*' => Http::response('<html></html>')]);

    $pauses = [];

    Sleep::whenFakingSleep(function ($duration) use (&$pauses): void {
        $pauses[] = $duration->totalMilliseconds;
    });

    foreach (range(1, 30) as $page) {
        ($this->client)()->get("https://portal.test/page-{$page}");
    }

    expect(count(array_unique($pauses)))->toBeGreaterThan(1);
});

it('waits as long as Retry-After asks before trying again', function (): void {
    Http::fake([
        '*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '25'])
            ->push('<html>ok</html>', 200),
    ]);

    $body = ($this->client)()->get('https://portal.test/page-1');

    expect($body)->toBe('<html>ok</html>');

    // The 25 s the portal asked for, not the 2 s default backoff.
    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 25_000, 1);
});

it('understands Retry-After given as an HTTP date', function (): void {
    Http::fake([
        '*' => Http::sequence()
            ->push('', 429, ['Retry-After' => gmdate('D, d M Y H:i:s \G\M\T', time() + 20)])
            ->push('<html>ok</html>', 200),
    ]);

    ($this->client)()->get('https://portal.test/page-1');

    Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds >= 18_000
        && $duration->totalMilliseconds <= 20_000, 1);
});

it('gives up instead of holding the scan open when told to wait too long', function (): void {
    Http::fake(['*' => Http::response('', 429, ['Retry-After' => '600'])]);

    expect(fn () => ($this->client)(capSeconds: 30)->get('https://portal.test/page-1'))
        ->toThrow(PortalRefused::class);

    // One attempt only — a ten-minute wait is not retried through.
    Http::assertSentCount(1);
    Sleep::assertSlept(fn ($duration): bool => $duration->totalMilliseconds > 60_000, 0);
});

it('reports a refusal distinctly from an ordinary failure', function (int $status): void {
    Http::fake(['*' => Http::response('', $status)]);

    expect(fn () => ($this->client)()->get('https://portal.test/page-1'))
        ->toThrow(PortalRefused::class);
})->with([403, 429, 503]);

it('still reports a missing page as PageNotFound', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    expect(fn () => ($this->client)()->get('https://portal.test/page-9'))
        ->toThrow(PageNotFound::class);

    // A 404 is never worth retrying — it would burn six seconds per city.
    Http::assertSentCount(1);
});

it('retries a server error with plain backoff', function (): void {
    Http::fake([
        '*' => Http::sequence()
            ->push('', 500)
            ->push('<html>ok</html>', 200),
    ]);

    expect(($this->client)()->get('https://portal.test/page-1'))->toBe('<html>ok</html>');

    Sleep::assertSlept(fn ($duration): bool => (int) $duration->totalMilliseconds === 2000, 1);
});

it('identifies itself as a browser reading Polish', function (): void {
    Http::fake(['*' => Http::response('<html></html>')]);

    ($this->client)()->get('https://portal.test/page-1');

    Http::assertSent(fn ($request): bool => $request->hasHeader('User-Agent', 'test-agent')
        && $request->hasHeader('Accept-Language', 'pl-PL,pl;q=0.9'));
});
