<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use App\Domain\Listing\Port\ListingProvider;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Search\SearchCriteria;
use Generator;

/**
 * Walks every wanted city, page by page, for any portal that paginates.
 *
 * Streams listings as they are parsed so a slow scan produces results
 * immediately instead of buffering hundreds of rows.
 */
final readonly class PaginatedListingProvider implements ListingProvider
{
    public function __construct(
        private PortalSearch $search,
        private PoliteHttpClient $http,
        private int $maxPagesPerCity,
        private int $refusalsBeforeBackingOff = 3,
    ) {}

    public function portal(): Portal
    {
        return $this->search->portal();
    }

    public function fetch(SearchCriteria $criteria): Generator
    {
        // Counted across the whole sweep, not per city: being turned away is
        // about our IP, and the portal does not care which city we ask for next.
        $refusals = 0;
        $refused = null;

        foreach ($criteria->cities as $city) {
            $page = 1;

            do {
                try {
                    $html = $this->http->get($this->search->urlFor($criteria, $city, $page));
                } catch (PageNotFound $e) {
                    // Gratka and Morizon 404 past the last page instead of
                    // serving an empty one. On page one it is a real failure
                    // (a city slug that stopped resolving), so it propagates.
                    if ($page === 1) {
                        throw $e;
                    }

                    break;
                } catch (PortalRefused $e) {
                    $refusals++;
                    $refused = $e;

                    // Walking the remaining pages after a refusal is how a
                    // temporary block becomes a permanent one. Stop knocking,
                    // but let the other cities be tried — they cost one request
                    // each and the sweep is failed either way (see below).
                    if ($refusals >= $this->refusalsBeforeBackingOff) {
                        throw new PortalRefused(
                            $e->status,
                            "Backed off {$this->search->portal()->value} after {$refusals} refusals: {$e->getMessage()}",
                        );
                    }

                    break;
                }

                $result = $this->search->parse($html);

                yield from $result->listings;

                // Portals that publish a page count get capped by it; the ones
                // that do not (the schema.org group) run until a page comes back
                // with nothing on it at all.
                $exhausted = $result->listings === [] && $result->skipped === 0;

                // Hard ceiling either way, so neither a bogus totalPages nor an
                // endlessly repeating page can turn one scan into thousands of
                // requests.
                $lastPage = $result->totalPages > 0
                    ? min($result->totalPages, $this->maxPagesPerCity)
                    : $this->maxPagesPerCity;

                $page++;
            } while (! $exhausted && $page <= $lastPage);
        }

        // A sweep that was refused anywhere must never look like a clean one.
        // ScanPortals only retires adverts for portals that scanned
        // successfully, so reporting success here would let a rate limit
        // deactivate listings we simply never got to read.
        if ($refused !== null) {
            throw new PortalRefused(
                $refused->status,
                "{$this->search->portal()->value} refused {$refusals} request(s) during this sweep: {$refused->getMessage()}",
            );
        }
    }
}
