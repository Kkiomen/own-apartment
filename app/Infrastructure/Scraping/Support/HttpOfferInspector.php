<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use App\Domain\Listing\Availability;
use App\Domain\Listing\OfferSnapshot;
use App\Domain\Listing\Port\OfferInspector;
use App\Domain\Listing\SourceReference;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Opens one advert's page and reports what it found there: whether the advert
 * is still up, and the full gallery if the portal's reader knows how to find it.
 *
 * One request, both answers. The gallery is the reason it is worth reading the
 * body at all — every portal's search payload carries only the first three
 * photos, while the offer page carries all of them.
 *
 * Whether the advert is over is read from three signals, in this order:
 *
 *  - the status code — OLX and Otodom both answer 410 for a withdrawn advert
 *    (Otodom still renders a full page with it), and a 404 means the same;
 *  - the page title — Otodom titles an archived offer "Archiwalne: …", which is
 *    a specific enough place for one word to be trusted;
 *  - a phrase anywhere on the page, for a portal that just says it in words.
 *
 * The status alone covers both portals we could check against live withdrawn
 * adverts. The other two are backup for the three portals we could not, which
 * is why both lists are config rather than code — a false positive is then
 * fixable on the VPS without a deploy. Every match is logged with the URL for
 * the same reason: the damage a bad phrase does is silent, flats quietly
 * disappearing off the list.
 */
final readonly class HttpOfferInspector implements OfferInspector
{
    /**
     * @param  array<string, OfferGallery>  $galleries  keyed by portal value; a portal
     *                                                  with no reader keeps the photos it has
     * @param  list<string>  $goneTitleMarkers  words that mean "over" in a page title
     * @param  list<string>  $goneMarkers  phrases that mean "over" anywhere on the page
     */
    public function __construct(
        private PoliteHttpClient $http,
        private LoggerInterface $logger,
        private array $galleries,
        private array $goneTitleMarkers,
        private array $goneMarkers,
    ) {}

    public function inspect(SourceReference $source): OfferSnapshot
    {
        try {
            $html = $this->http->get($source->url);
        } catch (PageNotFound) {
            return OfferSnapshot::unavailable(Availability::Gone);
        } catch (PortalRefused) {
            return OfferSnapshot::unavailable(Availability::Refused);
        } catch (Throwable $e) {
            // A timeout or a 500 says nothing about the flat. Reporting it as
            // anything but "we do not know" would retire listings on the
            // evidence of our own network having a bad minute.
            $this->logger->warning('Could not verify an advert', [
                'portal' => $source->portal->value,
                'url' => $source->url,
                'reason' => $e->getMessage(),
            ]);

            return OfferSnapshot::unavailable(Availability::Unreachable);
        }

        $marker = $this->goneMarkerIn($html);

        if ($marker !== null) {
            $this->logger->info('Offer page reads as expired', [
                'portal' => $source->portal->value,
                'url' => $source->url,
                'marker' => $marker,
            ]);

            // No gallery off a page that says the advert is over: whatever it
            // still renders is about to stop being true.
            return OfferSnapshot::unavailable(Availability::Gone);
        }

        return new OfferSnapshot(Availability::Available, $this->images($source, $html));
    }

    /**
     * @return list<string>
     */
    private function images(SourceReference $source, string $html): array
    {
        $gallery = $this->galleries[$source->portal->value] ?? null;

        return $gallery === null ? [] : $gallery->imagesFrom($html);
    }

    private function goneMarkerIn(string $html): ?string
    {
        return $this->firstMatch($this->goneTitleMarkers, $this->title($html))
            ?? $this->firstMatch($this->goneMarkers, $this->readableText($html));
    }

    /**
     * The page title, which is where a portal states an advert is over most
     * plainly: Otodom titles an archived offer "Archiwalne: 3 pokoje,
     * mieszkanie na sprzedaż — …". Narrow enough to be worth trusting, which
     * a whole-page search for the same word would not be.
     *
     * Read by hand rather than with a regex, for the reason the extractor is:
     * these pages run to hundreds of kilobytes and PCRE gives up on them
     * silently.
     */
    private function title(string $html): string
    {
        $open = stripos($html, '<title');
        $start = $open === false ? false : strpos($html, '>', $open);
        $end = $start === false ? false : stripos($html, '</title>', $start);

        if ($start === false || $end === false) {
            return '';
        }

        return $this->normalise(substr($html, $start + 1, $end - $start - 1));
    }

    /**
     * Tags are dropped, whitespace collapsed and the result lower-cased, so a
     * sentence broken across markup ("nie jest <b>już</b> dostępne") still
     * matches. Script contents are deliberately kept: the portals running Next
     * put the message in their JSON payload, not only in the rendered page.
     */
    private function readableText(string $html): string
    {
        return $this->normalise(strip_tags($html));
    }

    private function normalise(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * @param  list<string>  $markers
     */
    private function firstMatch(array $markers, string $text): ?string
    {
        if ($text === '') {
            return null;
        }

        foreach ($markers as $marker) {
            if ($marker !== '' && str_contains($text, mb_strtolower($marker))) {
                return $marker;
            }
        }

        return null;
    }
}
