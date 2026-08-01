<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Otodom;

use App\Domain\Listing\ListingData;
use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use App\Infrastructure\Scraping\Support\EmbeddedJsonExtractor;
use App\Infrastructure\Scraping\Support\PlainText;
use App\Infrastructure\Scraping\Support\ScrapedPage;
use DateTimeImmutable;
use Exception;
use Psr\Log\LoggerInterface;

/**
 * Turns an Otodom search page into ListingData.
 *
 * Otodom is a Next.js app and ships its result set as raw JSON in the
 * `__NEXT_DATA__` script tag (`props.pageProps.data.searchAds`).
 */
final readonly class OtodomListingParser
{
    private const SCRIPT_ID = '__NEXT_DATA__';

    /** Otodom spells counts as words, same as OLX but a different vocabulary. */
    private const ROOMS = [
        'ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5,
        'SIX' => 6, 'SEVEN' => 7, 'EIGHT' => 8, 'NINE' => 9, 'TEN' => 10,
    ];

    private const FLOORS = [
        'CELLAR' => -1, 'GROUND' => 0, 'FIRST' => 1, 'SECOND' => 2, 'THIRD' => 3,
        'FOURTH' => 4, 'FIFTH' => 5, 'SIXTH' => 6, 'SEVENTH' => 7, 'EIGHTH' => 8,
        'NINTH' => 9, 'TENTH' => 10,
    ];

    public function __construct(
        private EmbeddedJsonExtractor $extractor,
        private LoggerInterface $logger,
    ) {}

    public function parse(string $html): ScrapedPage
    {
        $data = $this->extractor->fromScriptTag($html, self::SCRIPT_ID);

        if ($data === null) {
            $this->logger->warning('Otodom: could not read __NEXT_DATA__');

            return new ScrapedPage([], 0);
        }

        $search = $data['props']['pageProps']['data']['searchAds'] ?? null;

        if (! is_array($search)) {
            $this->logger->warning('Otodom: no searchAds in the page payload');

            return new ScrapedPage([], 0);
        }

        $items = is_array($search['items'] ?? null) ? $search['items'] : [];
        $totalPages = $search['pagination']['totalPages'] ?? 1;

        $parsed = [];
        $skipped = 0;

        foreach ($items as $item) {
            $data = is_array($item) ? $this->toListingData($item) : null;

            if ($data === null) {
                $skipped++;

                continue;
            }

            $parsed[] = $data;
        }

        if ($parsed === [] && $skipped > 0) {
            $this->logger->error('Otodom: parsed 0 of {total} items', ['total' => $skipped]);
        }

        return new ScrapedPage($parsed, is_int($totalPages) ? $totalPages : 1, $skipped);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toListingData(array $item): ?ListingData
    {
        // The county-level search for Tomaszów also returns houses and plots.
        if (($item['estate'] ?? null) !== 'FLAT' || ($item['transaction'] ?? null) !== 'SELL') {
            return null;
        }

        $id = $item['id'] ?? null;
        $slug = $item['slug'] ?? null;
        $title = $item['title'] ?? null;

        if (! is_int($id) || ! is_string($slug) || ! is_string($title)) {
            return null;
        }

        $city = City::tryFromName($this->cityName($item));

        if ($city === null) {
            return null;
        }

        $price = $this->price($item);
        $area = $this->area($item);
        $rooms = self::ROOMS[$item['roomsNumber'] ?? ''] ?? null;

        if ($price === null || $area === null || $rooms === null) {
            return null;
        }

        return new ListingData(
            portal: Portal::Otodom,
            externalId: (string) $id,
            // `href` in the payload is a template ("[lang]/ad/..."), so the
            // public URL is rebuilt from the slug instead.
            url: 'https://www.otodom.pl/pl/oferta/'.$slug,
            title: $title,
            city: $city,
            area: $area,
            rooms: $rooms,
            price: $price,
            district: $this->district($item),
            floor: self::FLOORS[$item['floorNumber'] ?? ''] ?? null,
            images: $this->images($item),
            publishedAt: $this->publishedAt($item),
            // The search payload carries only a teaser, never the full advert.
            description: PlainText::from(is_string($item['shortDescription'] ?? null) ? $item['shortDescription'] : null),
            isPrivateSeller: is_bool($item['isPrivateOwner'] ?? null) ? $item['isPrivateOwner'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function images(array $item): array
    {
        $images = [];

        foreach (is_array($item['images'] ?? null) ? $item['images'] : [] as $image) {
            $url = is_array($image) ? ($image['large'] ?? $image['medium'] ?? null) : null;

            if (is_string($url)) {
                $images[] = $url;
            }
        }

        return array_slice($images, 0, 8);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function cityName(array $item): ?string
    {
        $name = $item['location']['address']['city']['name'] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function district(array $item): ?string
    {
        $locations = $item['location']['reverseGeocoding']['locations'] ?? null;

        if (! is_array($locations)) {
            return null;
        }

        foreach ($locations as $location) {
            if (is_array($location) && ($location['locationLevel'] ?? null) === 'district' && is_string($location['name'] ?? null)) {
                return $location['name'];
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function price(array $item): ?Price
    {
        // Developers hide prices behind "zapytaj o cenę"; without a number
        // there is nothing to compare against the budget.
        if (($item['hidePrice'] ?? false) === true) {
            return null;
        }

        $total = $item['totalPrice'] ?? null;

        if (! is_array($total) || ($total['currency'] ?? null) !== 'PLN') {
            return null;
        }

        $value = $total['value'] ?? null;

        return is_int($value) || is_float($value) ? Price::fromZloty($value) : null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function area(array $item): ?Area
    {
        $raw = $item['areaInSquareMeters'] ?? null;

        if (! is_int($raw) && ! is_float($raw)) {
            return null;
        }

        try {
            return Area::fromSquareMeters((float) $raw);
        } catch (Exception) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function publishedAt(array $item): ?DateTimeImmutable
    {
        $raw = $item['dateCreated'] ?? null;

        if (! is_string($raw)) {
            return null;
        }

        try {
            return new DateTimeImmutable($raw);
        } catch (Exception) {
            return null;
        }
    }
}
