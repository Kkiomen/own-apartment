<?php

declare(strict_types=1);

namespace App\Application\Scanning;

use App\Application\Listing\IngestionOutcome;
use App\Domain\Listing\ValueObject\Portal;

/**
 * What one portal produced during a scan. Mutable by design — it accumulates
 * while a slow paginated scrape streams in.
 */
final class PortalScanSummary
{
    /** @var array<string, int> */
    private array $counts = [];

    private int $fetched = 0;

    private int $retired = 0;

    private ?string $failure = null;

    public function __construct(public readonly Portal $portal) {}

    public function record(IngestionOutcome $outcome): void
    {
        $this->fetched++;
        $this->counts[$outcome->value] = ($this->counts[$outcome->value] ?? 0) + 1;
    }

    /** Adverts this portal has stopped showing. */
    public function recordRetired(int $count): void
    {
        $this->retired = $count;
    }

    public function retired(): int
    {
        return $this->retired;
    }

    public function fail(string $reason): void
    {
        $this->failure = $reason;
    }

    public function failed(): bool
    {
        return $this->failure !== null;
    }

    public function failure(): ?string
    {
        return $this->failure;
    }

    public function fetched(): int
    {
        return $this->fetched;
    }

    public function created(): int
    {
        return $this->countOf(IngestionOutcome::Created);
    }

    public function priceChanged(): int
    {
        return $this->countOf(IngestionOutcome::PriceChanged);
    }

    public function unchanged(): int
    {
        return $this->countOf(IngestionOutcome::Unchanged);
    }

    public function rejected(): int
    {
        return $this->countOf(IngestionOutcome::Rejected);
    }

    private function countOf(IngestionOutcome $outcome): int
    {
        return $this->counts[$outcome->value] ?? 0;
    }
}
