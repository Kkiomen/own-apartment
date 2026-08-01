<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Sleep;
use RuntimeException;
use Throwable;

/**
 * Every outbound scrape goes through here.
 *
 * We are one couple looking for one flat, not a data business: a pause between
 * requests and a small retry budget keep us from being rate-limited or banned,
 * which is the failure mode that actually ends this project.
 */
final readonly class PoliteHttpClient
{
    /**
     * How far the pause may wander from the configured value, either way.
     * Perfectly even spacing is a bot signal in its own right — no human loads
     * pages exactly 1500 ms apart for twenty minutes straight.
     */
    private const JITTER_PERCENT = 25;

    /** Backoff for retries the portal gave us no instructions about. */
    private const BASE_BACKOFF_MS = 2000;

    /** Statuses that mean "stop", not "something went wrong". */
    private const REFUSAL_STATUSES = [403, 429, 503];

    public function __construct(
        private Factory $http,
        private int $delayMs,
        private int $timeoutSeconds,
        private string $userAgent,
        private int $retryAfterCapSeconds,
    ) {}

    public function get(string $url): string
    {
        // Pause *before* the request so the very first call of a scan is
        // spaced from whatever the previous portal just did.
        Sleep::for($this->pauseMs())->milliseconds();

        $response = $this->http
            ->withHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'text/html,application/xhtml+xml',
                'Accept-Language' => 'pl-PL,pl;q=0.9',
            ])
            ->timeout($this->timeoutSeconds)
            // Only retry what a retry can fix: rate limits, server errors and
            // dropped connections. Retrying a 404 three times with backoff just
            // burns six seconds per city on every single scan.
            ->retry(3, $this->backoffMs(...), $this->worthRetrying(...), throw: false)
            ->get($url);

        if ($response->status() === 404) {
            throw new PageNotFound("Request to {$url} returned HTTP 404.");
        }

        if (in_array($response->status(), self::REFUSAL_STATUSES, true)) {
            throw new PortalRefused(
                $response->status(),
                "Request to {$url} was refused with HTTP {$response->status()}.",
            );
        }

        if ($response->failed()) {
            throw new RuntimeException("Request to {$url} failed with HTTP {$response->status()}.");
        }

        return $response->body();
    }

    private function pauseMs(): int
    {
        $jitter = intdiv($this->delayMs * self::JITTER_PERCENT, 100);

        return random_int($this->delayMs - $jitter, $this->delayMs + $jitter);
    }

    /**
     * When the portal says how long to wait, wait exactly that long. Ignoring
     * Retry-After and knocking again two seconds later is how a soft rate limit
     * becomes a hard ban.
     */
    private function backoffMs(int $attempt, Throwable $exception): int
    {
        return $this->retryAfterMs($exception) ?? self::BASE_BACKOFF_MS * $attempt;
    }

    private function worthRetrying(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if (! $exception instanceof RequestException) {
            return false;
        }

        $status = $exception->response->status();

        if (in_array($status, self::REFUSAL_STATUSES, true)) {
            $wait = $this->retryAfterMs($exception);

            // Being told to come back in ten minutes is not a hiccup to retry
            // through. Give up on the page and let the caller back off the
            // whole portal, rather than holding the scan open waiting.
            return $wait !== null && $wait <= $this->retryAfterCapSeconds * 1000;
        }

        return $status >= 500;
    }

    /** Retry-After is either a number of seconds or an HTTP date. */
    private function retryAfterMs(Throwable $exception): ?int
    {
        if (! $exception instanceof RequestException) {
            return null;
        }

        $header = trim($exception->response->header('Retry-After'));

        if ($header === '') {
            return null;
        }

        if (ctype_digit($header)) {
            return (int) $header * 1000;
        }

        $moment = strtotime($header);

        if ($moment === false) {
            return null;
        }

        return max(0, $moment - time()) * 1000;
    }
}
