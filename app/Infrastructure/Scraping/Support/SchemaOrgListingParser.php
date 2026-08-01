<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use App\Domain\Listing\ValueObject\Portal;
use Psr\Log\LoggerInterface;

/**
 * Reads listings from schema.org structured data.
 *
 * Portals differ only in where the offers array sits inside the ld+json block,
 * which the caller supplies as a key path.
 */
final readonly class SchemaOrgListingParser
{
    public function __construct(
        private LdJsonExtractor $extractor,
        private AccommodationOfferMapper $mapper,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param  list<string|int>  $offersPath
     */
    public function parse(Portal $portal, string $html, array $offersPath): ScrapedPage
    {
        $offers = null;

        foreach ($this->extractor->blocks($html) as $block) {
            $offers = $this->extractor->follow($block, $offersPath);

            if ($offers !== null) {
                break;
            }
        }

        if ($offers === null) {
            $this->logger->warning('{portal}: no structured data at the expected path', [
                'portal' => $portal->value,
                'path' => implode('.', array_map(strval(...), $offersPath)),
            ]);

            return new ScrapedPage([], 0);
        }

        $parsed = [];
        $skipped = 0;

        foreach ($offers as $offer) {
            $listing = $this->mapper->map($portal, $offer);

            if ($listing === null) {
                $skipped++;

                continue;
            }

            $parsed[] = $listing;
        }

        if ($parsed === [] && $skipped > 0) {
            $this->logger->error('{portal}: parsed 0 of {total} offers', [
                'portal' => $portal->value,
                'total' => $skipped,
            ]);
        }

        // These portals do not publish a page count, so pagination stops when a
        // page comes back empty (see PaginatedListingProvider).
        return new ScrapedPage($parsed, 0, $skipped);
    }
}
