<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Otodom;

use App\Domain\Listing\ValueObject\City;
use App\Domain\Search\SearchCriteria;

final class OtodomSearchUrl
{
    public function build(SearchCriteria $criteria, City $city, int $page): string
    {
        $query = [
            'priceMin' => (int) $criteria->minPrice->zloty(),
            'priceMax' => (int) $criteria->maxPrice->zloty(),
            'areaMin' => (int) $criteria->minArea->squareMeters,
            'by' => 'LATEST',
            'direction' => 'DESC',
        ];

        if ($page > 1) {
            $query['page'] = $page;
        }

        return 'https://www.otodom.pl/pl/wyniki/sprzedaz/mieszkanie/'
            .$this->locationPath($city).'?'.http_build_query($query);
    }

    /**
     * Otodom location paths are voivodeship/county/commune/city.
     *
     * Tomaszów Mazowiecki is the exception: every city-level path 404s, and only
     * the county `lodzkie/tomaszowski` resolves. That page mixes in neighbouring
     * villages, which is harmless — the domain re-checks the city on every
     * listing, so the extra rows are dropped rather than mis-filed.
     */
    private function locationPath(City $city): string
    {
        return match ($city) {
            City::Wroclaw => 'dolnoslaskie/wroclaw/wroclaw/wroclaw',
            City::Warszawa => 'mazowieckie/warszawa/warszawa/warszawa',
            City::Lodz => 'lodzkie/lodz/lodz/lodz',
            City::TomaszowMazowiecki => 'lodzkie/tomaszowski',
        };
    }
}
