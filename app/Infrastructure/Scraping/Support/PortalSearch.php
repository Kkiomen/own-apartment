<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Search\SearchCriteria;

/**
 * The only two things that actually differ between portals: what URL to ask
 * for, and how to read the answer.
 *
 * Extracted once a second portal existed and the shared shape was visible —
 * walking cities and pages is identical everywhere, and duplicating the
 * page-ceiling guard per portal is how one of them eventually loses it.
 */
interface PortalSearch
{
    public function portal(): Portal;

    public function urlFor(SearchCriteria $criteria, City $city, int $page): string;

    public function parse(string $html): ScrapedPage;
}
