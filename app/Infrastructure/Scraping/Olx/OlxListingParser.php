<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Olx;

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
 * Turns an OLX search page into ListingData.
 *
 * OLX embeds the whole result set as JSON in `window.__PRERENDERED_STATE__`,
 * so we read that instead of CSS selectors — the JSON is a real API contract
 * their own front-end depends on, while the markup is restyled constantly.
 */
final readonly class OlxListingParser
{
    private const MARKER = '__PRERENDERED_STATE__';

    /**
     * OLX reports room counts as words. "four" is really "4 or more", which is
     * fine for a minimum-rooms search but must not be read as exactly four.
     */
    private const ROOMS = [
        'one' => 1,
        'two' => 2,
        'three' => 3,
        'four' => 4,
    ];

    public function __construct(
        private EmbeddedJsonExtractor $extractor,
        private LoggerInterface $logger,
    ) {}

    public function parse(string $html): ScrapedPage
    {
        $state = $this->extractor->extract($html, self::MARKER);

        if ($state === null) {
            // Structure changed, or we were served a captcha/consent wall.
            $this->logger->warning('OLX: could not read the embedded state');

            return new ScrapedPage([], 0);
        }

        $listing = $state['listing']['listing'] ?? [];
        $ads = is_array($listing['ads'] ?? null) ? $listing['ads'] : [];
        $totalPages = is_int($listing['totalPages'] ?? null) ? $listing['totalPages'] : 1;

        $parsed = [];
        $skipped = 0;

        foreach ($ads as $ad) {
            $data = is_array($ad) ? $this->toListingData($ad) : null;

            if ($data === null) {
                $skipped++;

                continue;
            }

            $parsed[] = $data;
        }

        if ($parsed === [] && $skipped > 0) {
            // Everything unreadable means OLX changed something, not that the
            // market went quiet. Say so loudly.
            $this->logger->error('OLX: parsed 0 of {total} ads', ['total' => $skipped]);
        }

        return new ScrapedPage($parsed, $totalPages, $skipped);
    }

    /**
     * @param  array<string, mixed>  $ad
     */
    private function toListingData(array $ad): ?ListingData
    {
        if (($ad['isActive'] ?? true) !== true) {
            return null;
        }

        $id = $ad['id'] ?? null;
        $url = $ad['url'] ?? null;
        $title = $ad['title'] ?? null;

        if (! is_int($id) || ! is_string($url) || ! is_string($title)) {
            return null;
        }

        $location = is_array($ad['location'] ?? null) ? $ad['location'] : [];
        $city = City::tryFromName(is_string($location['cityName'] ?? null) ? $location['cityName'] : null);

        if ($city === null) {
            return null;
        }

        $price = $this->price($ad);
        $params = $this->params($ad);
        $area = $this->area($params);
        $rooms = $this->rooms($params);

        if ($price === null || $area === null || $rooms === null) {
            return null;
        }

        return new ListingData(
            portal: Portal::Olx,
            externalId: (string) $id,
            url: $url,
            title: $title,
            city: $city,
            area: $area,
            rooms: $rooms,
            price: $price,
            district: is_string($location['districtName'] ?? null) ? $location['districtName'] : null,
            floor: $this->floor($params),
            images: $this->images($ad),
            publishedAt: $this->publishedAt($ad),
            description: PlainText::from(is_string($ad['description'] ?? null) ? $ad['description'] : null),
            isPrivateSeller: is_bool($ad['isBusiness'] ?? null) ? ! $ad['isBusiness'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $ad
     * @return list<string>
     */
    private function images(array $ad): array
    {
        $photos = is_array($ad['photos'] ?? null) ? $ad['photos'] : [];

        // Enough to judge a flat without turning the modal into a slideshow.
        return array_slice(array_values(array_filter($photos, 'is_string')), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $ad
     * @return array<string, string> param key => normalised value
     */
    private function params(array $ad): array
    {
        $params = [];

        foreach (is_array($ad['params'] ?? null) ? $ad['params'] : [] as $param) {
            if (is_array($param) && is_string($param['key'] ?? null) && is_string($param['normalizedValue'] ?? null)) {
                $params[$param['key']] = $param['normalizedValue'];
            }
        }

        return $params;
    }

    /**
     * @param  array<string, mixed>  $ad
     */
    private function price(array $ad): ?Price
    {
        $regular = $ad['price']['regularPrice'] ?? null;

        if (! is_array($regular)) {
            return null;
        }

        // A price in EUR read as PLN would put a 130 000 € flat straight into
        // our 300-600k budget. Anything not PLN is dropped.
        if (($regular['currencyCode'] ?? null) !== 'PLN') {
            return null;
        }

        $value = $regular['value'] ?? null;

        return is_int($value) || is_float($value) ? Price::fromZloty($value) : null;
    }

    /**
     * @param  array<string, string>  $params
     */
    private function area(array $params): ?Area
    {
        $raw = $params['m'] ?? null;

        if ($raw === null || ! is_numeric($raw)) {
            return null;
        }

        try {
            return Area::fromSquareMeters((float) $raw);
        } catch (Exception) {
            // Implausible area — a parsing artefact, not a flat.
            return null;
        }
    }

    /**
     * @param  array<string, string>  $params
     */
    private function rooms(array $params): ?int
    {
        return self::ROOMS[$params['rooms'] ?? ''] ?? null;
    }

    /**
     * @param  array<string, string>  $params
     */
    private function floor(array $params): ?int
    {
        $raw = $params['floor_select'] ?? null;

        if ($raw === null || preg_match('/(-?\d+)/', $raw, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * @param  array<string, mixed>  $ad
     */
    private function publishedAt(array $ad): ?DateTimeImmutable
    {
        $raw = $ad['createdTime'] ?? null;

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
