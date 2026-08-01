<?php

declare(strict_types=1);

namespace App\Application\Scanning;

use App\Application\Listing\IngestListing;
use App\Domain\Listing\Port\ListingMaintenance;
use App\Domain\Listing\Port\ListingProvider;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Search\SearchCriteria;
use App\Domain\Shared\Clock;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sweep every enabled portal, fold the results into our listings, and retire
 * the flats that have stopped being advertised.
 *
 * Invoked by a driving adapter (the scheduled console command), never directly.
 */
final readonly class ScanPortals
{
    /**
     * @param  list<ListingProvider>  $providers
     */
    public function __construct(
        private array $providers,
        private IngestListing $ingest,
        private SearchCriteria $criteria,
        private LoggerInterface $logger,
        private ListingMaintenance $maintenance,
        private AppraiseListings $appraise,
        private Clock $clock,
        private int $staleAfterDays,
    ) {}

    public function __invoke(): ScanReport
    {
        $report = new ScanReport;

        foreach ($this->providers as $provider) {
            $summary = $this->scan($provider);

            // Only retire adverts for a portal we actually managed to read. A
            // portal being down for a fortnight must not empty our list.
            if (! $summary->failed()) {
                $summary->recordRetired($this->retireStaleSources($provider->portal()));
            }

            $report->add($summary);
        }

        $report->recordRetiredListings($this->maintenance->deactivateListingsWithoutActiveSources());

        // Last, and over the whole set: the benchmarks are relative, so a new
        // batch of listings changes what counts as a bargain among the old ones.
        $report->recordAppraised(($this->appraise)());

        return $report;
    }

    private function retireStaleSources(Portal $portal): int
    {
        $cutoff = $this->clock->now()->modify("-{$this->staleAfterDays} days");

        return $this->maintenance->deactivateSourcesNotSeenSince($portal, $cutoff);
    }

    private function scan(ListingProvider $provider): PortalScanSummary
    {
        $summary = new PortalScanSummary($provider->portal());

        try {
            foreach ($provider->fetch($this->criteria) as $listingData) {
                $result = ($this->ingest)($listingData, $this->criteria);
                $summary->record($result->outcome);
            }
        } catch (Throwable $e) {
            // One portal changing its markup must not cost us the other four.
            // Listings already ingested in this pass are kept.
            $summary->fail($e->getMessage());

            $this->logger->error('Portal scan failed', [
                'portal' => $provider->portal()->value,
                'exception' => $e,
            ]);
        }

        return $summary;
    }
}
