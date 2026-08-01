<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\NieruchomosciOnline;

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Search\SearchCriteria;
use App\Infrastructure\Scraping\Support\PortalSearch;
use App\Infrastructure\Scraping\Support\SchemaOrgListingParser;
use App\Infrastructure\Scraping\Support\ScrapedPage;

/**
 * Nieruchomości-online is the odd one out: its price and area filters are
 * ignored no matter how they are passed (positional slots, named `priceFrom`
 * /`areaFrom` params — both verified against the live site, both returned the
 * unfiltered set).
 *
 * So we ask only for "flats for sale in this city" and let the domain do the
 * filtering it does for every portal anyway. The cost is pages, not
 * correctness, which is why this portal gets its own higher page ceiling in
 * config/search.php.
 */
final readonly class NieruchomosciOnlineSearch implements PortalSearch
{
    /** NOL nests its offers one level deeper than Gratka and Morizon. */
    private const OFFERS_PATH = ['mainEntity', 'offers', 0, 'offers'];

    public function __construct(private SchemaOrgListingParser $parser) {}

    public function portal(): Portal
    {
        return Portal::NieruchomosciOnline;
    }

    public function urlFor(SearchCriteria $criteria, City $city, int $page): string
    {
        // Positional query format: 3,<type>,<transaction>,,<city>
        $search = '3,mieszkanie,sprzedaz,,'.rawurlencode($city->label());

        $url = 'https://www.nieruchomosci-online.pl/szukaj.html?'.$search;

        return $page > 1 ? $url.'&p='.$page : $url;
    }

    public function parse(string $html): ScrapedPage
    {
        return $this->parser->parse($this->portal(), $html, self::OFFERS_PATH);
    }
}
