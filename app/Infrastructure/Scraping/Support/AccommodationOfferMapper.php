<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use App\Domain\Listing\ListingData;
use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use Exception;

/**
 * Maps a schema.org `Offer` wrapping an `Accommodation` into ListingData.
 *
 * Shared by three portals that publish the same structured data. Extracted only
 * once the third implementation proved the shape really is identical — the
 * differences between them are where the offers array sits and how they spell
 * a city, both of which stay in the portal's own class.
 */
final class AccommodationOfferMapper
{
    /**
     * @param  array<string, mixed>  $offer
     */
    public function map(Portal $portal, array $offer): ?ListingData
    {
        $url = $offer['url'] ?? null;
        $name = $offer['name'] ?? null;
        $item = $offer['itemOffered'] ?? null;

        if (! is_string($url) || ! is_string($name) || ! is_array($item)) {
            return null;
        }

        $externalId = $this->externalId($url);
        $city = $this->city($item, $url);
        $price = $this->price($offer);
        $area = $this->area($item);
        $rooms = is_int($item['numberOfRooms'] ?? null) ? $item['numberOfRooms'] : null;

        if ($externalId === null || $city === null || $price === null || $area === null || $rooms === null) {
            return null;
        }

        return new ListingData(
            portal: $portal,
            externalId: $externalId,
            url: $url,
            title: $name,
            city: $city,
            area: $area,
            rooms: $rooms,
            price: $price,
            district: $this->district($item, $city),
            floor: is_int($item['floorLevel'] ?? null) ? $item['floorLevel'] : null,
            // Structured data exposes one image per offer, not a gallery.
            images: is_string($offer['image'] ?? null) ? [$offer['image']] : [],
            description: PlainText::from(is_string($item['description'] ?? null) ? $item['description'] : null),
        );
    }

    /**
     * Every one of these portals ends its offer URLs with the advert's own id
     * (`/ob/48322667`, `/26724845.html`), which is the only stable identifier
     * the structured data exposes.
     */
    private function externalId(string $url): ?string
    {
        if (preg_match_all('/(\d{5,})/', $url, $matches) === 0) {
            return null;
        }

        $numbers = $matches[1];

        return end($numbers) ?: null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function city(array $item, string $url): ?City
    {
        $locality = $item['address']['addressLocality'] ?? null;

        $city = City::tryFromName(is_string($locality) ? $locality : null);

        if ($city !== null) {
            return $city;
        }

        // Gratka prints the *district* as addressLocality ("Różanka"), so the
        // city has to come from the URL slug instead. Without this fallback
        // every Wrocław listing would be dropped as an unknown city.
        return City::tryFromName($url);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function district(array $item, City $city): ?string
    {
        $locality = $item['address']['addressLocality'] ?? null;

        if (! is_string($locality) || $locality === '') {
            return null;
        }

        // When addressLocality *is* the city it carries no extra information.
        return City::tryFromName($locality) === $city ? null : $locality;
    }

    /**
     * @param  array<string, mixed>  $offer
     */
    private function price(array $offer): ?Price
    {
        if (($offer['priceCurrency'] ?? null) !== 'PLN') {
            return null;
        }

        $raw = $offer['price'] ?? null;

        // Published as a string ("598000.00"), so numeric strings are expected.
        if (! is_numeric($raw)) {
            return null;
        }

        return Price::fromZloty((float) $raw);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function area(array $item): ?Area
    {
        $raw = $item['floorSize']['value'] ?? null;

        if (! is_numeric($raw)) {
            return null;
        }

        try {
            return Area::fromSquareMeters((float) $raw);
        } catch (Exception) {
            return null;
        }
    }
}
