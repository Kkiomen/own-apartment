<?php

declare(strict_types=1);

namespace App\Application\Scanning;

final class ScanReport
{
    /** @var list<PortalScanSummary> */
    private array $summaries = [];

    private int $retiredListings = 0;

    private int $appraised = 0;

    public function add(PortalScanSummary $summary): void
    {
        $this->summaries[] = $summary;
    }

    /** Flats no portal advertises any more — sold, or withdrawn. */
    public function recordRetiredListings(int $count): void
    {
        $this->retiredListings = $count;
    }

    public function retiredListings(): int
    {
        return $this->retiredListings;
    }

    public function recordAppraised(int $count): void
    {
        $this->appraised = $count;
    }

    public function appraised(): int
    {
        return $this->appraised;
    }

    /**
     * @return list<PortalScanSummary>
     */
    public function summaries(): array
    {
        return $this->summaries;
    }

    public function totalCreated(): int
    {
        return array_sum(array_map(static fn (PortalScanSummary $s): int => $s->created(), $this->summaries));
    }

    public function totalPriceChanged(): int
    {
        return array_sum(array_map(static fn (PortalScanSummary $s): int => $s->priceChanged(), $this->summaries));
    }

    public function totalFetched(): int
    {
        return array_sum(array_map(static fn (PortalScanSummary $s): int => $s->fetched(), $this->summaries));
    }

    /**
     * @return list<PortalScanSummary>
     */
    public function failures(): array
    {
        return array_values(array_filter($this->summaries, static fn (PortalScanSummary $s): bool => $s->failed()));
    }
}
