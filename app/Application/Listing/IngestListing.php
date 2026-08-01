<?php

declare(strict_types=1);

namespace App\Application\Listing;

use App\Domain\Alert\ListingAlert;
use App\Domain\Alert\Port\AlertNotifier;
use App\Domain\Listing\Listing;
use App\Domain\Listing\ListingData;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\Port\PriceHistoryRepository;
use App\Domain\Search\SearchCriteria;
use App\Domain\Shared\Clock;

/**
 * Take one scraped listing and fold it into what we already know.
 *
 * This is where deduplication, price-change detection and alerting meet. It
 * talks to the world exclusively through ports, so the whole thing is testable
 * with in-memory fakes and no database.
 */
final readonly class IngestListing
{
    public function __construct(
        private ListingRepository $listings,
        private PriceHistoryRepository $priceHistory,
        private AlertNotifier $notifier,
        private Clock $clock,
    ) {}

    public function __invoke(ListingData $data, SearchCriteria $criteria): IngestionResult
    {
        // Adapters are never trusted to have filtered correctly — a portal that
        // silently ignores a query parameter would otherwise flood the app.
        $reasons = $criteria->rejectionReasons($data);

        if ($reasons !== []) {
            return IngestionResult::rejected($reasons);
        }

        $existing = $this->findExisting($data);
        $now = $this->clock->now();

        if ($existing === null) {
            return $this->createNew($data, $now);
        }

        $change = $existing->registerSighting($data, $now);
        $this->listings->save($existing);

        if ($change === null) {
            return IngestionResult::unchanged($existing);
        }

        $this->priceHistory->record($existing, $change);

        // A price going *up* is worth recording but not worth a notification.
        if ($change->isDrop()) {
            $this->notifier->send(ListingAlert::priceDrop($existing, $change));
        }

        return IngestionResult::priceChanged($existing, $change);
    }

    /**
     * Portal identity first (stable across price changes), cross-portal
     * fingerprint second.
     */
    private function findExisting(ListingData $data): ?Listing
    {
        return $this->listings->findBySource($data->portal, $data->externalId)
            ?? $this->listings->findByFingerprint($data->fingerprint());
    }

    private function createNew(ListingData $data, \DateTimeImmutable $now): IngestionResult
    {
        $listing = Listing::fromFirstSighting($data, $now);

        $this->listings->save($listing);
        $this->notifier->send(ListingAlert::newMatch($listing));

        return IngestionResult::created($listing);
    }
}
