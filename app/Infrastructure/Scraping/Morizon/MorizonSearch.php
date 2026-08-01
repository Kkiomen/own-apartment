<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Morizon;

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Search\SearchCriteria;
use App\Infrastructure\Scraping\Support\PortalSearch;
use App\Infrastructure\Scraping\Support\SchemaOrgListingParser;
use App\Infrastructure\Scraping\Support\ScrapedPage;

final readonly class MorizonSearch implements PortalSearch
{
    private const OFFERS_PATH = ['offers', 'offers'];

    public function __construct(private SchemaOrgListingParser $parser) {}

    public function portal(): Portal
    {
        return Portal::Morizon;
    }

    public function urlFor(SearchCriteria $criteria, City $city, int $page): string
    {
        $query = [
            'ps[price_from]' => (int) $criteria->minPrice->zloty(),
            'ps[price_to]' => (int) $criteria->maxPrice->zloty(),
            'ps[living_area_from]' => (int) $criteria->minArea->squareMeters,
        ];

        if ($page > 1) {
            $query['page'] = $page;
        }

        return 'https://www.morizon.pl/mieszkania/'.$this->slug($city).'/?'.http_build_query($query);
    }

    public function parse(string $html): ScrapedPage
    {
        return $this->parser->parse($this->portal(), $html, self::OFFERS_PATH);
    }

    /**
     * Like Otodom, Morizon has no city slug for Tomaszów Mazowiecki — every
     * variant 404s and only the county `tomaszowski` resolves. Its page mixes
     * in neighbouring towns, which the domain drops by city. Gratka, on the
     * same index, does have the city slug; the front-ends differ.
     */
    private function slug(City $city): string
    {
        return match ($city) {
            City::Wroclaw => 'wroclaw',
            City::Warszawa => 'warszawa',
            City::Lodz => 'lodz',
            City::TomaszowMazowiecki => 'tomaszowski',
        };
    }
}
