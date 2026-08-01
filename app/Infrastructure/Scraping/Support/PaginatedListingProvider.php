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
    ) {}

    public function portal(): Portal
    {
        return $this->search->portal();
    }

    public function fetch(SearchCriteria $criteria): Generator
    {
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
    }
}
