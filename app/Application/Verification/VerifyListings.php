<?php

declare(strict_types=1);

namespace App\Application\Verification;

use App\Domain\Listing\Availability;
use App\Domain\Listing\OfferSnapshot;
use App\Domain\Listing\Port\ListingMaintenance;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\Port\ListingVerification;
use App\Domain\Listing\Port\OfferInspector;
use App\Domain\Listing\SourceReference;
use App\Domain\Shared\Clock;
use Psr\Log\LoggerInterface;

/**
 * Re-check adverts we already know about, by opening the offer page itself.
 *
 * A search sweep can only ever say "I did not see it", which is why retiring on
 * that evidence alone has to wait a fortnight (see ScanPortals). Asking the
 * offer page directly gives a definite answer — OLX answers HTTP 410 for a
 * withdrawn advert — so a flat that is gone drops off the list the same day
 * instead of a fortnight later.
 *
 * The same page pays for itself twice: it also carries the advert's whole
 * gallery, where the search results a scan reads carry only the first three
 * photos. Collecting it here rather than during a scan is what keeps it free —
 * the request is being made either way.
 *
 * Deliberately bounded per run: this is one request per advert, and being
 * blocked is the failure mode that ends this project. Adverts are worked
 * through oldest-check-first, so a run picks up where the last one stopped.
 */
final readonly class VerifyListings
{
    public function __construct(
        private ListingVerification $verification,
        private OfferInspector $offers,
        private ListingRepository $listings,
        private ListingMaintenance $maintenance,
        private Clock $clock,
        private LoggerInterface $logger,
        private int $recheckAfterHours,
        private int $maxPerRun,
    ) {}

    public function __invoke(?int $limit = null): VerificationReport
    {
        $now = $this->clock->now();
        $report = new VerificationReport;

        $due = $this->verification->dueForVerification(
            $now->modify("-{$this->recheckAfterHours} hours"),
            max(1, $limit ?? $this->maxPerRun),
        );

        /** @var list<string> $refused portals that turned us away during this run */
        $refused = [];

        foreach ($due as $source) {
            // Knocking on the next advert after being turned away is how a
            // temporary block becomes a permanent one. The rest of that portal's
            // queue keeps its old timestamp and is simply picked up next run.
            if (in_array($source->portal->value, $refused, true)) {
                $report->recordSkipped();

                continue;
            }

            $snapshot = $this->offers->inspect($source);
            $answer = $snapshot->availability;

            if ($answer === Availability::Refused) {
                $refused[] = $source->portal->value;
                $report->recordRefusal($source->portal);
                $report->recordSkipped();

                $this->logger->warning('Portal refused a verification request', [
                    'portal' => $source->portal->value,
                    'url' => $source->url,
                ]);

                continue;
            }

            $this->record($source, $snapshot, $report);
        }

        // Only worth a query when something actually went: a flat leaves the
        // list once no portal advertises it any more, not when one of its
        // adverts does.
        if ($report->gone() > 0) {
            $report->recordRetiredListings($this->maintenance->deactivateListingsWithoutActiveSources());
        }

        return $report;
    }

    private function record(SourceReference $source, OfferSnapshot $snapshot, VerificationReport $report): void
    {
        $answer = $snapshot->availability;

        if ($answer === Availability::Gone) {
            $this->verification->recordGone($source->portal, $source->externalId, $this->clock->now());
            $report->recordGone();

            $this->logger->info('Advert withdrawn by the portal', [
                'portal' => $source->portal->value,
                'url' => $source->url,
            ]);

            return;
        }

        // An unreachable page is stamped as checked too. It says nothing about
        // the flat, but without the stamp one permanently broken URL would be
        // re-tried at the head of the queue on every single run, and the rest
        // of the backlog would never be reached.
        $this->verification->recordChecked($source->portal, $source->externalId, $this->clock->now());

        if ($answer !== Availability::Available) {
            $report->recordUnreachable();

            return;
        }

        $report->recordAvailable();

        if ($this->refreshGallery($source, $snapshot)) {
            $report->recordGalleryFilled();
        }
    }

    /**
     * The photos the offer page carried, which is the only place the whole
     * gallery exists. The aggregate refuses a poorer set than it already has,
     * so a portal reader that breaks costs us nothing.
     */
    private function refreshGallery(SourceReference $source, OfferSnapshot $snapshot): bool
    {
        if ($snapshot->images === []) {
            return false;
        }

        $listing = $this->listings->findBySource($source->portal, $source->externalId);

        if ($listing === null || ! $listing->refreshGallery($snapshot->images)) {
            return false;
        }

        $this->listings->save($listing);

        return true;
    }
}
