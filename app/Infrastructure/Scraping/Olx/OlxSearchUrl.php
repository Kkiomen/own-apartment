<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Olx;

use App\Domain\Listing\ValueObject\City;
use App\Domain\Search\SearchCriteria;

/**
 * Builds OLX search URLs from our criteria.
 *
 * OLX's own filters are used only to keep the result set small — the domain
 * re-checks everything anyway, so a filter OLX silently ignores costs us
 * bandwidth, never correctness.
 */
final class OlxSearchUrl
{
    private const PATH = '/nieruchomosci/mieszkania/sprzedaz';

    public function build(SearchCriteria $criteria, City $city, int $page): string
    {
        // No sort parameter: OLX ignores `search[order]=created_at:desc`, and
        // even its organic results come back in relevance order, not by date
        // (verified against the live site — page one mixed adverts from March
        // and August). Nothing may assume page one holds the newest listings;
        // every page has to be walked.
        $query = [
            'search[filter_float_price:from]' => (int) $criteria->minPrice->zloty(),
            'search[filter_float_price:to]' => (int) $criteria->maxPrice->zloty(),
            'search[filter_float_m:from]' => (int) $criteria->minArea->squareMeters,
        ];

        if ($page > 1) {
            $query['page'] = $page;
        }

        return 'https://www.olx.pl'.self::PATH.'/'.$this->slug($city).'/?'.http_build_query($query);
    }

    /**
     * OLX's own city slugs. Kept here rather than on the City enum: how a
     * portal spells a city is the adapter's problem, not the domain's.
     */
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
