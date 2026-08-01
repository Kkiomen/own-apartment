<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Otodom;

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Search\SearchCriteria;
use App\Infrastructure\Scraping\Support\PortalSearch;
use App\Infrastructure\Scraping\Support\ScrapedPage;

final readonly class OtodomSearch implements PortalSearch
{
    public function __construct(
        private OtodomSearchUrl $urls,
        private OtodomListingParser $parser,
    ) {}

    public function portal(): Portal
    {
        return Portal::Otodom;
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
