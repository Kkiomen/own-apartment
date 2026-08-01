<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Listing\IngestListing;
use App\Application\Scanning\AppraiseListings;
use App\Application\Scanning\ScanPortals;
use App\Domain\Alert\Port\AlertNotifier;
use App\Domain\Listing\Appraisal\ListingAppraiser;
use App\Domain\Listing\ListingPlausibility;
use App\Domain\Listing\Port\ListingAppraisals;
use App\Domain\Listing\Port\ListingCatalog;
use App\Domain\Listing\Port\ListingMaintenance;
use App\Domain\Listing\Port\ListingProvider;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\Port\PriceHistoryRepository;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Search\SearchCriteria;
use App\Domain\Shared\Clock;
use App\Infrastructure\Clock\SystemClock;
use App\Infrastructure\Notification\LoggingAlertNotifier;
use App\Infrastructure\Persistence\Eloquent\EloquentListingAppraisals;
use App\Infrastructure\Persistence\Eloquent\EloquentListingCatalog;
use App\Infrastructure\Persistence\Eloquent\EloquentListingMaintenance;
use App\Infrastructure\Persistence\Eloquent\EloquentListingRepository;
use App\Infrastructure\Persistence\Eloquent\EloquentPriceHistoryRepository;
use App\Infrastructure\Scraping\Gratka\GratkaSearch;
use App\Infrastructure\Scraping\Morizon\MorizonSearch;
use App\Infrastructure\Scraping\NieruchomosciOnline\NieruchomosciOnlineSearch;
use App\Infrastructure\Scraping\Olx\OlxListingParser;
use App\Infrastructure\Scraping\Olx\OlxSearch;
use App\Infrastructure\Scraping\Olx\OlxSearchUrl;
use App\Infrastructure\Scraping\Otodom\OtodomListingParser;
use App\Infrastructure\Scraping\Otodom\OtodomSearch;
use App\Infrastructure\Scraping\Otodom\OtodomSearchUrl;
use App\Infrastructure\Scraping\Support\PaginatedListingProvider;
use App\Infrastructure\Scraping\Support\PoliteHttpClient;
use App\Infrastructure\Scraping\Support\PortalSearch;
use App\Infrastructure\Scraping\Support\SchemaOrgListingParser;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * The single place where ports meet adapters.
 *
 * Swapping any outbound dependency — a different notification channel, a
 * different database, a fake in tests — is a one-line change here and nothing
 * else in the application knows.
 */
final class DomainServiceProvider extends ServiceProvider
{
    /** Container tag every portal scraper registers under. */
    public const PROVIDER_TAG = 'listing-providers';

    public const OLX = 'scraping.provider.olx';

    public const OTODOM = 'scraping.provider.otodom';

    public const GRATKA = 'scraping.provider.gratka';

    public const MORIZON = 'scraping.provider.morizon';

    public const NIERUCHOMOSCI_ONLINE = 'scraping.provider.nieruchomosci-online';

    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(ListingRepository::class, EloquentListingRepository::class);
        $this->app->singleton(ListingCatalog::class, EloquentListingCatalog::class);
        $this->app->singleton(ListingMaintenance::class, EloquentListingMaintenance::class);
        $this->app->singleton(ListingAppraisals::class, EloquentListingAppraisals::class);

        $this->app->singleton(ListingAppraiser::class, static fn (Application $app): ListingAppraiser => ListingAppraiser::with(
            comfortableBudgetZloty: (int) config('search.appraisal.comfortable_price_pln'),
            cheapPerMeterThresholdPercent: (float) config('search.appraisal.cheap_per_m2_threshold_percent'),
            longOnMarketDays: (int) config('search.appraisal.long_on_market_days'),
            tooGoodToBeTruePercent: (float) config('search.appraisal.too_good_to_be_true_percent'),
            plausibility: $app->make(ListingPlausibility::class),
        ));

        $this->app->singleton(ListingPlausibility::class, static fn (): ListingPlausibility => ListingPlausibility::fromZloty(
            (int) config('search.suspicious_below_price_per_m2'),
        ));
        $this->app->singleton(PriceHistoryRepository::class, EloquentPriceHistoryRepository::class);

        // No channel wired up yet by decision — see LoggingAlertNotifier.
        $this->app->singleton(AlertNotifier::class, LoggingAlertNotifier::class);

        $this->app->singleton(PoliteHttpClient::class, static fn (Application $app): PoliteHttpClient => new PoliteHttpClient(
            http: $app->make(HttpFactory::class),
            delayMs: (int) config('search.scraping.delay_between_requests_ms'),
            timeoutSeconds: (int) config('search.scraping.request_timeout_seconds'),
            userAgent: (string) config('search.scraping.user_agent'),
            retryAfterCapSeconds: (int) config('search.scraping.retry_after_cap_seconds'),
        ));

        $this->registerScrapers();

        $this->app->singleton(SearchCriteria::class, static function (): SearchCriteria {
            /** @var array{min_area_m2: float, min_rooms: int, min_price_pln: int, max_price_pln: int, cities: list<string>} $criteria */
            $criteria = config('search.criteria');

            return SearchCriteria::fromConfig($criteria);
        });

        $this->app->singleton(ScanPortals::class, fn (Application $app): ScanPortals => new ScanPortals(
            providers: $this->resolveProviders($app),
            ingest: $app->make(IngestListing::class),
            criteria: $app->make(SearchCriteria::class),
            logger: $app->make(LoggerInterface::class),
            maintenance: $app->make(ListingMaintenance::class),
            appraise: $app->make(AppraiseListings::class),
            clock: $app->make(Clock::class),
            staleAfterDays: (int) config('search.stale_after_days'),
        ));
    }

    /**
     * Adding a portal: write a PortalSearch, register it here, add it to the
     * enabled map. No existing class is edited (OCP).
     */
    private function registerScrapers(): void
    {
        $this->bindScraper(self::OLX, static fn (Application $app): PortalSearch => new OlxSearch(
            $app->make(OlxSearchUrl::class),
            $app->make(OlxListingParser::class),
        ));

        $this->bindScraper(self::OTODOM, static fn (Application $app): PortalSearch => new OtodomSearch(
            $app->make(OtodomSearchUrl::class),
            $app->make(OtodomListingParser::class),
        ));

        $this->bindScraper(self::GRATKA, static fn (Application $app): PortalSearch => new GratkaSearch(
            $app->make(SchemaOrgListingParser::class),
        ));

        $this->bindScraper(self::MORIZON, static fn (Application $app): PortalSearch => new MorizonSearch(
            $app->make(SchemaOrgListingParser::class),
        ));

        $this->bindScraper(self::NIERUCHOMOSCI_ONLINE, static fn (Application $app): PortalSearch => new NieruchomosciOnlineSearch(
            $app->make(SchemaOrgListingParser::class),
        ));

        $this->app->tag($this->enabledScrapers(), self::PROVIDER_TAG);
    }

    /**
     * @param  callable(Application): PortalSearch  $search
     */
    private function bindScraper(string $abstract, callable $search): void
    {
        $this->app->singleton($abstract, static function (Application $app) use ($search): PaginatedListingProvider {
            $portalSearch = $search($app);

            return new PaginatedListingProvider(
                search: $portalSearch,
                http: $app->make(PoliteHttpClient::class),
                maxPagesPerCity: (int) (
                    config('search.scraping.max_pages_override.'.$portalSearch->portal()->value)
                    ?? config('search.scraping.max_pages_per_city')
                ),
                refusalsBeforeBackingOff: (int) config('search.scraping.refusals_before_backing_off'),
            );
        });
    }

    /**
     * Gated by config/search.php so a misbehaving portal can be switched off
     * without a deploy.
     *
     * @return list<string>
     */
    private function enabledScrapers(): array
    {
        $byPortal = [
            Portal::Olx->value => self::OLX,
            Portal::Otodom->value => self::OTODOM,
            Portal::Gratka->value => self::GRATKA,
            Portal::Morizon->value => self::MORIZON,
            Portal::NieruchomosciOnline->value => self::NIERUCHOMOSCI_ONLINE,
        ];

        $enabled = [];

        foreach ($byPortal as $portal => $abstract) {
            if (config("search.portals.{$portal}") === true) {
                $enabled[] = $abstract;
            }
        }

        return $enabled;
    }

    /**
     * @return list<ListingProvider>
     */
    private function resolveProviders(Application $app): array
    {
        return array_values(array_filter(
            iterator_to_array($app->tagged(self::PROVIDER_TAG)),
            static fn (mixed $provider): bool => $provider instanceof ListingProvider,
        ));
    }
}
