<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Gratka;

use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Search\SearchCriteria;
use App\Infrastructure\Scraping\Support\PortalSearch;
use App\Infrastructure\Scraping\Support\SchemaOrgListingParser;
use App\Infrastructure\Scraping\Support\ScrapedPage;

/**
 * Gratka and Morizon run the same Nuxt front-end over the same index and return
 * identical result counts. Both are scraped anyway: the fingerprint merges the
 * overlap into one listing, and if one of them ever breaks the other keeps
 * working.
 */
final readonly class GratkaSearch implements PortalSearch
{
    /** Where the offers array sits inside Gratka's ld+json Product block. */
    private const OFFERS_PATH = ['offers', 'offers'];

    public function __construct(private SchemaOrgListingParser $parser) {}

    public function portal(): Portal
    {
        return Portal::Gratka;
    }

    public function urlFor(SearchCriteria $criteria, City $city, int $page): string
    {
        $query = [
            'cena-calkowita:min' => (int) $criteria->minPrice->zloty(),
            'cena-calkowita:max' => (int) $criteria->maxPrice->zloty(),
            'powierzchnia-w-m2:min' => (int) $criteria->minArea->squareMeters,
        ];

        if ($page > 1) {
            $query['page'] = $page;
        }

        return 'https://gratka.pl/nieruchomosci/mieszkania/'.$this->slug($city).'/sprzedaz?'
            .http_build_query($query);
    }

    public function parse(string $html): ScrapedPage
    {
        return $this->parser->parse($this->portal(), $html, self::OFFERS_PATH);
    }

    private function slug(City $city): string
    {
        return match ($city) {
            City::Wroclaw => 'wroclaw',
            City::Warszawa => 'warszawa',
            City::Lodz => 'lodz',
            City::TomaszowMazowiecki => 'tomaszow-mazowiecki',
        };
    }
}
