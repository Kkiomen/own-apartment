<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Listing\ListingData;
use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Search\SearchCriteria;

/**
 * Test data that matches our real criteria by default, so every test only has
 * to state the one thing it is actually about.
 */
final class ListingDataFactory
{
    public static function matching(
        Portal $portal = Portal::Olx,
        string $externalId = 'olx-1',
        ?City $city = null,
        float $areaM2 = 56.0,
        int $rooms = 3,
        int $priceZloty = 549_000,
        ?string $title = null,
        array $images = [],
    ): ListingData {
        return new ListingData(
            portal: $portal,
            externalId: $externalId,
            url: $portal->baseUrl()."/oferta/{$externalId}",
            title: $title ?? "Mieszkanie {$rooms} pokoje, {$areaM2} m²",
            city: $city ?? City::Wroclaw,
            area: Area::fromSquareMeters($areaM2),
            rooms: $rooms,
            price: Price::fromZloty($priceZloty),
            district: 'Krzyki',
            images: $images,
        );
    }

    public static function criteria(): SearchCriteria
    {
        return new SearchCriteria(
            minArea: Area::fromSquareMeters(50),
            minRooms: 3,
            minPrice: Price::fromZloty(300_000),
            maxPrice: Price::fromZloty(600_000),
            cities: [City::Wroclaw, City::Warszawa, City::Lodz, City::TomaszowMazowiecki],
        );
    }
}
