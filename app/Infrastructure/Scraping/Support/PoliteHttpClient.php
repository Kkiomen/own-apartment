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
 * We are one couple looking for one flat, not a data business: a fixed pause
 * between requests and a small retry budget keep us from being rate-limited or
 * banned, which is the failure mode that actually ends this project.
 */
final readonly class PoliteHttpClient
{
    public function __construct(
        private Factory $http,
        private int $delayMs,
        private int $timeoutSeconds,
        private string $userAgent,
    ) {}

    public function get(string $url): string
    {
        // Pause *before* the request so the very first call of a scan is
        // spaced from whatever the previous portal just did.
        Sleep::for($this->delayMs)->milliseconds();

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
            ->retry(3, 2000, $this->worthRetrying(...), throw: false)
            ->get($url);

        if ($response->status() === 404) {
            throw new PageNotFound("Request to {$url} returned HTTP 404.");
        }

        if ($response->failed()) {
            throw new RuntimeException("Request to {$url} failed with HTTP {$response->status()}.");
        }

        return $response->body();
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

        return $status === 429 || $status >= 500;
    }
}
