<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Olx;

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Search\SearchCriteria;
use App\Infrastructure\Scraping\Support\PortalSearch;
use App\Infrastructure\Scraping\Support\ScrapedPage;

final readonly class OlxSearch implements PortalSearch
{
    public function __construct(
        private OlxSearchUrl $urls,
        private OlxListingParser $parser,
    ) {}

    public function portal(): Portal
    {
        return Portal::Olx;
    }

    public function urlFor(SearchCriteria $criteria, City $city, int $page): string
    {
        return $this->urls->build($criteria, $city, $page);
    }

    public function parse(string $html): ScrapedPage
    {
        return $this->parser->parse($html);
    }
}
