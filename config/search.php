<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Search criteria
    |--------------------------------------------------------------------------
    |
    | The flat we are actually looking for. Hard limits, no tolerance margin:
    | min 50 m² (room for kids later), min 3 rooms (home office now, guest room
    | later), 300k-600k PLN. Anything outside this is not shown at all.
    |
    */

    'criteria' => [
        'min_area_m2' => (float) env('SEARCH_MIN_AREA_M2', 50),
        'min_rooms' => (int) env('SEARCH_MIN_ROOMS', 3),
        'min_price_pln' => (int) env('SEARCH_MIN_PRICE_PLN', 300_000),
        'max_price_pln' => (int) env('SEARCH_MAX_PRICE_PLN', 600_000),
        'cities' => ['wroclaw', 'warszawa', 'lodz', 'tomaszow-mazowiecki'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Plausibility warning
    |--------------------------------------------------------------------------
    |
    | Below this price per m² a listing is almost certainly not an ordinary
    | flat — a share in a property, a plot, or a mis-parsed area. Such listings
    | are flagged in the UI, never hidden: deciding to ignore one is yours, and
    | a sudden pile of warnings is how you notice a parser has broken.
    |
    */

    'suspicious_below_price_per_m2' => (int) env('SEARCH_SUSPICIOUS_PRICE_PER_M2', 3000),

    /*
    |--------------------------------------------------------------------------
    | What counts as a find
    |--------------------------------------------------------------------------
    |
    | Tuned for a first mortgage kept deliberately small, on a flat that will
    | not need another 100 000 zł spent on it. The label weights themselves live
    | in App\Domain\Listing\Appraisal\ListingLabel — change them there and the
    | whole ranking follows.
    |
    */

    'appraisal' => [
        // The price above which the loan stops feeling comfortable. Listings at
        // or below this get the strongest single boost in the ranking.
        'comfortable_price_pln' => (int) env('SEARCH_COMFORTABLE_PRICE_PLN', 500_000),

        // How far below the city median price per m² a listing has to sit
        // before it is worth calling cheap.
        'cheap_per_m2_threshold_percent' => (float) env('SEARCH_CHEAP_PER_M2_PERCENT', 10),

        // After this many days on the portal the seller is probably ready to
        // talk about the price.
        'long_on_market_days' => (int) env('SEARCH_LONG_ON_MARKET_DAYS', 60),

        // Below this much under the city median, a price stops being a bargain
        // and starts being a red flag — a share in a property, an auction, or a
        // mis-parsed area. Relative because 3 777 zł/m² is normal in Tomaszów
        // and impossible in Wrocław.
        'too_good_to_be_true_percent' => (float) env('SEARCH_TOO_GOOD_PERCENT', 40),
    ],

    /*
    |--------------------------------------------------------------------------
    | Enabled portals
    |--------------------------------------------------------------------------
    |
    | Keys must match App\Domain\Listing\ValueObject\Portal cases. Disabling a
    | portal here stops it being scanned without touching any code.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Retiring listings
    |--------------------------------------------------------------------------
    |
    | A flat whose advert stops appearing is treated as sold or withdrawn and
    | drops out of the list. Generous by default: page ceilings mean a live
    | listing can legitimately miss a few scans, and a listing that reappears is
    | revived automatically.
    |
    */

    'stale_after_days' => (int) env('SEARCH_STALE_AFTER_DAYS', 14),

    'portals' => [
        'olx' => (bool) env('PORTAL_OLX_ENABLED', true),
        'otodom' => (bool) env('PORTAL_OTODOM_ENABLED', true),
        'gratka' => (bool) env('PORTAL_GRATKA_ENABLED', true),
        'morizon' => (bool) env('PORTAL_MORIZON_ENABLED', true),
        'nieruchomosci-online' => (bool) env('PORTAL_NIERUCHOMOSCI_ONLINE_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scraping behaviour
    |--------------------------------------------------------------------------
    |
    | Be a polite guest: portals rate-limit aggressively and we are one couple
    | looking for one flat, not a data business. Slow and reliable beats fast
    | and banned.
    |
    */

    'scraping' => [
        'delay_between_requests_ms' => (int) env('SCRAPE_DELAY_MS', 1500),
        'max_pages_per_city' => (int) env('SCRAPE_MAX_PAGES', 25),

        /*
         * Per-portal ceilings. Nieruchomości-online ignores every price and
         * area filter we can pass, so its result set is unfiltered and needs
         * many more pages to cover the same ground. Correctness is unaffected
         * (the domain filters regardless) — this only buys coverage.
         */
        'max_pages_override' => [
            'nieruchomosci-online' => (int) env('SCRAPE_MAX_PAGES_NOL', 80),
        ],

        'request_timeout_seconds' => (int) env('SCRAPE_TIMEOUT', 20),

        /*
         * A 429 carrying Retry-After is obeyed to the second. Past this cap we
         * stop waiting and give up on the page instead — being told to come
         * back in ten minutes is not a hiccup to retry through.
         */
        'retry_after_cap_seconds' => (int) env('SCRAPE_RETRY_AFTER_CAP', 30),

        /*
         * Consecutive refusals (403/429/503) before the whole portal is
         * abandoned for this pass. Continuing to walk pages after being turned
         * away is how a temporary block becomes a permanent one.
         */
        'refusals_before_backing_off' => (int) env('SCRAPE_REFUSALS_BEFORE_BACKOFF', 3),
        /*
         * Worth refreshing every few months. Real Chrome updates itself, so a
         * User-Agent claiming a version that is a year and a half old is itself
         * a bot signal — the opposite of what this string is here for.
         */
        'user_agent' => env('SCRAPE_USER_AGENT', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36'),
    ],

];
