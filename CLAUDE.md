# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project purpose

Private (non-commercial) side project. The goal is a self-hosted app running on a VPS that continuously scrapes Polish real-estate listing portals (OLX, Otodom, and others) for **apartments for sale**, stores them, tracks **price changes over time**, and notifies the owner when a listing matches the target criteria.

Target criteria (baseline — should be configurable, not hardcoded):

- area > 50 m²
- 3 rooms
- price up to 600 000 PLN
- cities: Wrocław, Warszawa, Łódź, Tomaszów Mazowiecki

Design implication: the core of the app is **scheduled background work** (scrapers → normalized listings → deduplication across portals → price-history snapshots → criteria matching → alerts), not request/response CRUD. New domain code should live behind queued jobs and scheduled commands so a VPS worker can run it unattended.

## Current state

Feature-complete and green (211 tests). What exists:

- `App\Domain` — `Listing` aggregate, `Price`/`Area`/`City`/`Portal` value objects, `Fingerprint` (cross-portal dedup), `SearchCriteria` specification, `PriceChange`, and the ports.
- `App\Application` — `IngestListing` (dedup + price-change detection + alerting) and `ScanPortals` (per-portal orchestration, one portal failing does not abort the rest).
- `App\Infrastructure` — Eloquent persistence (`listings`, `listing_sources`, `price_changes`), `SystemClock`, `LoggingAlertNotifier`, `listings:scan` command scheduled hourly.

- `App\Infrastructure\Scraping` — shared support plus **all five portal adapters**, verified end-to-end against the live sites.
- Browse UI — `ListingCatalog` read port, `EloquentListingCatalog`, `ListingsController` and `resources/js/pages/Listings.vue` (filter by city/portal/price drops, five sort orders, paging, per-listing price history).
- Housekeeping — `ListingMaintenance` retires adverts a portal stopped showing, `VerifyListings` re-checks known adverts against their own offer page, and `ListingPlausibility` flags listings too cheap per m² to be a flat.
- `App\Domain\Listing\Appraisal` — labels and a score that rank listings ("Poniżej 500 000 zł", "31% poniżej mediany m²", "Do remontu"), recomputed over the whole set after every scan by `AppraiseListings`. Drives the default `best_value` sort.
- Deployment — `deploy.sh` stands the Docker stack up on a VPS in one idempotent command.

### How the ranking works

Weights live in `App\Domain\Listing\Appraisal\ListingLabel` and encode one specific situation: a first mortgage kept deliberately small, on a flat that will not swallow another 100 000 zł. That is why total price (+30) outranks price per m² (+25), and why "needs renovation" (−25) costs nearly as much as a bargain gains. Thresholds are in `config('search.appraisal')`.

Two guards stop junk topping the list, both found by running against live data rather than by tests:

- An **absolute** floor (`suspicious_below_price_per_m2`) catches nonsense like 1 549 zł/m².
- A **relative** floor (`too_good_to_be_true_percent`, 40% under the city median) catches the rest. No single absolute number works across four cities — 3 777 zł/m² is ordinary in Tomaszów and impossible in Wrocław.

Such listings stay on the list with their warning; they are simply never advertised as finds.

Cheaper-elsewhere detection needs `variant_key` (city|area|rooms, **without** price). The fingerprint deliberately includes price so one flat at two prices stays two rows; `variant_key` is how those rows find each other again. It only speaks up past `appraisal.cheaper_elsewhere_min_pln` (default 1 000 zł): two adverts for one flat rarely agree to the grosz, and a 40-grosz gap rendered as "To samo mieszkanie taniej o **0 zł**" on live data.

### Retiring and flagging

**Retiring** (`ScanPortals` → `ListingMaintenance`, threshold `config('search.stale_after_days')`, default 14): an advert unseen for that long is deactivated, and a listing drops off the browse screen only once *no* portal advertises it. Two guards, both tested — retirement only runs for a portal whose scan actually **succeeded** (a portal down for a fortnight must not empty the list), and a listing that reappears is revived automatically by `registerSighting`. The window is generous on purpose: page ceilings mean a live listing can legitimately miss a few scans.

**Verifying** (`VerifyListings` → `listings:verify`, config `search.verification`): the other half of retiring, and the only way to learn a flat is *gone* rather than merely unseen. A sweep can only say "I did not see it", which is why it waits a fortnight; the offer page answers directly — OLX returns **HTTP 410** for a withdrawn advert — so the flat leaves the list the same day. `OfferAvailability` is a port with one HTTP adapter for all five portals, reading three signals in order:

- **the status** — checked against live withdrawn adverts: OLX **and Otodom both answer 410**. Otodom serves a *full 355 kB offer page* with that 410, so the body must never be read before the status or an archived flat looks available.
- **the page title** — Otodom titles an archived offer `Archiwalne: 3 pokoje, mieszkanie na sprzedaż — …`. One word (`archiwaln`) is safe in a title, which is portal-generated, and would not be safe anywhere else on the page.
- **a phrase anywhere on the page**, for a portal that only says it in words.

The last two are backup for the three portals no live withdrawn advert was available for; both lists live in config (`verification.gone_title_markers`, `gone_markers`) so a false positive is fixable on the VPS without a deploy, and every match is logged with its URL — the damage a bad phrase does is silent, flats quietly vanishing off the list.

Both marker paths read the page **without a regex**, for the same reason `EmbeddedJsonExtractor` does: these pages run to hundreds of kilobytes and PCRE gives up on them silently.

**The same request also fetches the gallery.** Every portal's *search* payload carries only the **first 3 photos** of an advert — measured, not assumed: one OLX flat has 13 on its own page, an Otodom flat 20, both 3 in search results. So the offer page is the only place a full gallery exists, and the verification pass is already opening it; collecting photos anywhere else would mean paying for the request twice.

`OfferGallery` is one reader per portal, because the payloads have nothing in common:

| Portal | Where the gallery is | Yield |
|---|---|---|
| OLX | **markup** — `div[data-testid="ad-photo"]`, with `ld+json` `Product.image` as the floor | 13 vs 5 vs 3 in search |
| Otodom | `__NEXT_DATA__` → `props.pageProps.ad.images[].large` | 20 vs 3 in search |
| Gratka / Morizon / Nieruchomości-online | `ld+json` `Product.image` — **unverified**, no live offer page was available | silent if absent |

OLX is the one place this codebase parses markup, and only because it has to: an OLX offer page carries no state blob at all (`__PRERENDERED_STATE__` belongs to the search app), and its only structured data lists 5 of the 13 photos. `data-testid` is picked because it exists for OLX's own tests, which makes it the most stable attribute on a page whose styling churns.

Two guards make a broken reader free: `Listing::refreshGallery()` **refuses a poorer set** than it already holds, and `registerSighting` keeps the richer of the two rather than the newest.

That second guard fixes a bug of its own, and it is the reason some cards showed **exactly one photo**. The schema.org three publish a single `image` per search result, and they are scanned last — so for any flat also advertised there, one photo used to overwrite OLX's three. Last-writer-wins is the wrong rule for galleries; richest-wins is the right one.

The distinctions that carry the safety are in `Availability`: only `Gone` is a fact about the flat. `Unreachable` (timeout, 500) and `Refused` (403/429/503) must never retire anything — collapsing them is how one bad minute on the VPS empties the list. A refusal also stops that portal for the rest of the run, and its adverts keep their old timestamp so they are simply picked up next time. An unreachable advert *is* stamped as checked, or one permanently broken URL would sit at the head of the queue for ever.

Bounded on purpose: `max_per_run` adverts (default 60, one request each), oldest-check-first, every other hour at `:47` — half an hour off the scan, so two streams of requests are not hitting one portal at once.

**Flagging** (`ListingPlausibility`, threshold `config('search.suspicious_below_price_per_m2')`, default 3000 zł/m²): a warning on the card, never a filter. Hiding these would also hide the day a parser starts producing nonsense — a sudden pile of warnings is the signal. On live data this flags exactly 1 listing in 191.

Source links are grouped **per portal**, not per advert, with an `×N` badge when one portal carries the same flat more than once (two agents posting it). Without grouping a card can show six buttons for four portals.

The app is feature-complete for its purpose. The remaining known gap is deliberate: no notification channel is wired up (see `LoggingAlertNotifier`).

### How the portal adapters work

A portal is two things: `PortalSearch` (build a URL, parse a page) and `PaginatedListingProvider`, which walks cities and pages identically for everyone. Adding a portal means one `PortalSearch`, one parser, and one binding in `DomainServiceProvider` — the pagination ceiling and politeness are not reimplemented per portal.

Both portals ship their result set as embedded JSON, so the parsers read that rather than CSS selectors — the JSON backs their own front-ends, while markup is restyled constantly.

| Portal | Payload | Path | Portal-side filters |
|---|---|---|---|
| OLX | `window.__PRERENDERED_STATE__` (escaped JS string) | `listing.listing.ads[]` | work |
| Otodom | `__NEXT_DATA__` (raw JSON script tag) | `props.pageProps.data.searchAds` | work |
| Gratka | `ld+json` Product | `offers.offers[]` | work |
| Morizon | `ld+json` Product | `offers.offers[]` | work |
| Nieruchomości-online | `ld+json` CollectionPage | `mainEntity.offers.0.offers[]` | **ignored** |

`EmbeddedJsonExtractor` handles the two JS-embedded shapes; `LdJsonExtractor` + `SchemaOrgListingParser` + `AccommodationOfferMapper` serve the three schema.org portals, since all three publish an identical `Offer`/`Accommodation` structure.

Only OLX and Otodom publish a page count. The schema.org three stop paginating when a page comes back empty, bounded by the config ceiling either way.

Gratka and Morizon run the same front-end over the **same index** and return identical result counts, so most of their listings are duplicates of each other — the fingerprint merges them. Both are scraped anyway so one breaking does not cost the other.

Things that bite, all covered by tests:

- **Never use a regex to pull the blob out.** Search pages run to ~3.6 MB and PCRE's backtrack limit makes `preg_match` return false *silently* — indistinguishable from "no listings today". The extractor scans characters by hand for this reason.
- Both portals report rooms as words, with **different vocabularies** — OLX `"two"/"three"/"four"` (where `"four"` means *4 or more*), Otodom `"TWO"/"THREE"` and floors as `"GROUND"/"FIRST"/…`.
- Prices carry a currency. A EUR listing read as PLN lands inside the 300-600k budget and looks like a bargain.
- Otodom hides some prices behind "zapytaj o cenę" (`hidePrice: true`) and mixes houses and plots into results — filtered on `estate === 'FLAT'`.
- Otodom's `href` is a template containing `[lang]`; the real offer URL is rebuilt from `slug`.
- **Neither Otodom nor Morizon has a working city path for Tomaszów Mazowiecki** — every variant 404s, only the county (`lodzkie/tomaszowski`, `tomaszowski`) resolves. Those pages include neighbouring villages, which the domain drops by city. Gratka, on Morizon's own index, *does* have the city slug. This is exactly why adapters are never trusted to filter.
- **Gratka prints the district as `addressLocality`** ("Różanka" for a Wrocław flat). Taken at face value that is an unknown city and every listing is dropped, so `AccommodationOfferMapper` falls back to matching the city against the offer URL.
- **Nieruchomości-online ignores every price and area filter** — positional slots and named `priceFrom`/`areaFrom` params were both tried against the live site and both returned the unfiltered set. It therefore gets its own deeper page ceiling in `config('search.scraping.max_pages_override')`; correctness is unaffected because the domain filters regardless.
- Items missing area or rooms are skipped and counted in `ScrapedPage::$skipped`. If a portal changes shape the honest symptom is "parsed 0, skipped 44", and the parser logs an error.

### Not getting banned

Being blocked is the failure mode that actually ends this project, so the measures below are load-bearing, not decoration. All live in `config('search.scraping')`, `PoliteHttpClient` and `PaginatedListingProvider`, and all are tested with `Sleep::fake()` so none of it costs wall-clock.

- **A jittered pause before every request** (±25% around `delay_between_requests_ms`). Perfectly even spacing is a bot signal in itself — nobody loads pages exactly 1500 ms apart for twenty minutes.
- **`Retry-After` is obeyed to the second.** Ignoring it and knocking again on a flat 2 s backoff is precisely how a soft rate limit becomes a hard ban. Both header forms (seconds and HTTP-date) are handled. Past `retry_after_cap_seconds` we stop waiting and give up on the page instead of holding the scan open.
- **A circuit breaker per portal.** 403/429/503 arrive as `PortalRefused` (distinct from a generic failure on purpose); after `refusals_before_backing_off` the whole portal is abandoned for that pass. Walking the remaining 25–80 pages after being turned away is what makes a temporary block permanent.
- **A refused sweep never reports success.** `PaginatedListingProvider` rethrows at the end even if it recovered, because `ScanPortals` only retires adverts for portals that scanned cleanly — a silent failure would let a rate limit deactivate listings we simply never read. Two existing provider tests guard this; a first attempt at the breaker broke both.
- **Scheduled at `hourlyAt(17)`, not `hourly()`.** `:00` is when every cron on the internet fires — the portals' busiest minute and the easiest spike to pick a scraper out of.
- **The `user_agent` needs refreshing every few months.** Real Chrome updates itself, so a User-Agent pinned to a version a year and a half old is itself a bot signal — the opposite of what the string is for.
- **A hard `max_pages_per_city` ceiling** so a bogus `totalPages` cannot turn one scan into thousands of requests.

### Environment caveats discovered while building

- **Pest 5 requires PHP ≥ 8.4.1; local Herd PHP is 8.4.0.** `composer.json` pins `config.platform.php` to `8.4.24` (the Docker runtime) so the lock resolves consistently, and sets `config.platform-check: false` so the 0.0.1 gap does not abort every local run. Updating Herd's PHP to the latest 8.4.x would let that override be dropped.
- `phpstan` needs `--memory-limit=1G` (wired into the `types:check` script) — it crashes on the 128M default on Windows.
- `pestphp/pest` was declared in `composer.json` but missing from `composer.lock` in the starter kit; it has been installed and the lock synced.

## Code standards (non-negotiable)

### Language: English

All code is written in English — class, method, variable and route names, DB tables and columns, comments, PHPDoc, test names, commit messages, log messages, exception messages. `PriceChange`, not `ZmianaCeny`; `rooms_count`, not `liczba_pokoi`.

Polish appears in exactly two places: user-facing UI strings (via `lang/pl/` translation files, never hardcoded in Blade/Vue) and scraped data *values* (a listing's title, district, description — that's data, not code).

### Everything is tested

No feature is done without tests. Concretely for this project:

- **Scrapers never hit the network in tests.** Save a real portal response once into `tests/Fixtures/<portal>/…html` and parse that. Use `Http::fake()` / `Http::preventStrayRequests()` so a stray live request fails the suite loudly instead of silently making tests flaky and slow.
- **Parsers get the nastiest fixtures you can find**: missing price, "cena do negocjacji", area as `52,5 m²` vs `52.5`, no room count, duplicate listing across portals. These are where the bugs will actually be.
- **Test behaviour, not structure.** Assert "a price drop creates one `price_changes` row and fires the alert", not "the method was called".
- Feature tests for jobs/commands (dispatch, assert DB state + `Notification::fake()`), unit tests for pure logic (parsers, criteria matching, value objects).
- `composer test` (Pint → PHPStan level 7 → tests) must be green before anything is considered finished.

### Architecture: ports & adapters (hexagonal)

**All communication with the outside world goes through a port.** Anything that is not this application's own logic — portals, database, notifications, filesystem, clock, HTTP — is reached only via an interface owned by the core, with a concrete adapter behind it. No exceptions: if a class does `Http::get()`, `Listing::query()` or `Mail::send()` inside domain logic, it is wrong.

Layers and the one rule that governs them — **dependencies only ever point inward**:

```
app/
  Domain/          entities, value objects, domain services, PORT interfaces
                   -> pure PHP. Must NOT import Illuminate\*, Eloquent, or any vendor SDK.
  Application/     use cases orchestrating the domain (ScanPortals, DetectPriceChanges,
                   MatchCriteria). Depends on Domain only, talks to the world via ports.
  Infrastructure/  ADAPTERS: Scraping/Olx, Scraping/Otodom, Persistence/Eloquent,
                   Notification/Telegram, Http (controllers), Console (commands), Jobs.
                   Depends inward; nothing depends on it except the container bindings.
```

**Driving (inbound) adapters** — what triggers the app: Artisan commands, the scheduler, queued jobs, Inertia controllers. They translate an external trigger into a use-case call and must contain no business logic.

**Driven (outbound) adapters** — what the app reaches for: `OlxListingSource`, `OtodomListingSource` (implementing a `ListingSource` port), `EloquentListingRepository` (`ListingRepository`), `TelegramNotifier` (`AlertNotifier`), `SystemClock` (`Clock`).

Rules that make this real rather than decorative:

- **Ports are defined by the core, in the core's vocabulary** — `ListingSource::fetchFor(SearchCriteria): ListingData[]`. Never shape a port around the vendor's API or Eloquent's method names; that leaks the adapter back into the core and defeats the whole thing.
- **Eloquent models are an infrastructure detail**, not domain entities. They live in `Infrastructure/Persistence` and are mapped to/from domain objects at the repository boundary. Domain objects never travel with `save()` on them.
- **Bind ports to adapters in a service provider**, one place, so swapping an implementation is a one-line change.
- The per-portal Strategy and the DIP bullet below are the *same idea* seen from two angles — a portal scraper is just a driven adapter behind the `ListingSource` port.

This pays for itself in testing: the core is tested against **in-memory fake adapters** (`InMemoryListingRepository`, `FakeListingSource` returning fixture DTOs) with no Laravel boot, no DB, no network — fast and deterministic. Adapters get their own narrow tests against saved fixtures. Only a thin layer of end-to-end feature tests exercises the wiring.

Enforce the boundary mechanically rather than by discipline — Pest 5 arch tests can assert it:

```php
arch('domain stays framework-agnostic')
    ->expect('App\Domain')
    ->not->toUse(['Illuminate', 'App\Infrastructure']);
```

Note the deliberate tension with YAGNI below: **ports & adapters is a standing architectural decision that applies from the first portal**, not an abstraction to be earned later. What stays subject to YAGNI is everything *inside* a layer — don't invent a plugin registry, a config-driven parser DSL, or a second level of indirection behind the port.

### SOLID, applied to this codebase

Not as slogans — as the specific shape this project should take:

- **SRP** — split *fetching* (HTTP, rate limiting, retries), *parsing* (HTML/JSON → DTO), *normalisation* (DTO → canonical listing), and *persistence*. One class each. A "scraper" that does all four is the thing to avoid.
- **OCP** — adding a third portal means adding a class, not editing existing ones. Portal-specific quirks live in that portal's class.
- **LSP** — every portal scraper is interchangeable behind the interface; none may require special-casing at the call site.
- **ISP** — small interfaces (`Fetcher`, `Parser`, `Notifier`) over one fat `ScraperInterface` with ten methods.
- **DIP** — jobs depend on interfaces resolved from the container, which is what makes fixture-based testing possible without mocking HTTP internals.

### Patterns worth using here (and only when earned)

- **Strategy** — one implementation per portal behind a common interface.
- **DTO / Value Objects** — `Price`, `Area`, `ListingData`. Immutable, validated in the constructor. Kills a whole class of "is this PLN or grosze, m² or m" bugs.
- **Specification** — search criteria (>50 m², 3 rooms, ≤600k, city list) as composable objects, so the same criteria can filter a DB query *and* test a single listing in memory.
- **Events/Listeners** — `ListingMatched`, `PriceDropped` → notification listeners. Keeps alerting out of the scraping path.

Patterns are earned, not applied upfront (YAGNI). Do not build a plugin architecture for portal #1. Extract the abstraction when the **second** implementation exists and the shared shape is actually visible.

### KISS / DRY, with the caveat that matters here

Prefer boring, readable Laravel over clever indirection. But be careful with DRY across scrapers: OLX and Otodom look similar right up until they don't, and a premature shared base class becomes a tangle of `if ($this->portal === …)`. **Duplicated parsing code is cheaper than the wrong abstraction** — deduplicate only genuinely identical logic.

## Commands

```bash
composer setup       # first-time install: deps, .env, key, migrate, npm build
composer dev         # runs php artisan serve + queue:listen + vite concurrently
composer test        # config:clear -> pint --test -> phpstan -> artisan test
composer lint        # pint --parallel (formats PHP)
composer types:check # phpstan (larastan, level 7)
composer ci:check    # what CI runs: JS lint + prettier + vue-tsc + composer test
```

Frontend-only: `npm run dev`, `npm run build`, `npm run lint` (eslint --fix), `npm run format` (prettier on `resources/`), `npm run types:check` (vue-tsc).

Single test: `php artisan test --filter=test_returns_a_successful_response` or `php artisan test tests/Feature/ExampleTest.php`. Suites are `Unit` and `Feature` (see `phpunit.xml`).

Note `composer test` fails on formatting/static-analysis before it ever runs the tests — run `composer lint` first if Pint complains.

## Docker (VPS target)

The Docker stack is the deployment target; Herd stays for local dev. Env for containers is `.env.docker` (gitignored, template in `.env.docker.example`) — deliberately separate from `.env` so the two setups don't fight.

Deployment is one command, safe to re-run for updates:

```bash
./deploy.sh
```

It creates `.env.docker` from the template if missing, generates `APP_KEY` and a database password only when they are still placeholders, builds, and brings the stack up. Nothing is regenerated on a second run — a fresh `APP_KEY` on every deploy would silently reset sessions.

Manual equivalents:

```bash
docker compose --env-file .env.docker up -d --build
docker compose --env-file .env.docker logs -f seed        # the first scan
docker compose --env-file .env.docker exec postgres psql -U apartment -d apartment   # DB has no host port
```

`--env-file .env.docker` is required on every invocation — compose reads `.env` for `${...}` interpolation otherwise.

Services (`docker-compose.yml`):

- **web** (nginx) — the *only* service publishing a host port (`APP_PORT`, default 8090).
- **migrate** — one-shot `artisan migrate --force`. Everything else waits on `service_completed_successfully`. Migrations live here and nowhere else, so app/queue/scheduler can't race the schema.
- **seed** — one-shot `listings:scan --if-empty`. Fills a fresh deployment instead of leaving an empty screen until the top of the hour, and skips itself once listings exist so restarts don't re-scrape five portals.
- **app** — php-fpm.
- **queue** — `queue:work`. Scrapers run here, so it's on the `edge` network for egress; `stop_grace_period: 60s` + the `pcntl` extension let an in-flight scrape finish on shutdown.
- **scheduler** — `schedule:work`, ticks every minute against `routes/console.php`. Register scraping cadence there.
- **postgres** — no `ports:`, attached only to the `internal: true` network. Unreachable from the host *and* has no outbound route.

### Sharing a VPS with another app

This box also hosts a second, unrelated Laravel app, so nothing here may assume it owns a port or a Docker resource name. The stack is already built for it and the properties below must not regress:

- **Exactly one host port is published**: `web` → `${APP_PORT}`, currently **8090**. Postgres, the queue and the scheduler sit on private networks, so `APP_PORT` is the single value that can ever collide. Verify with `docker compose --env-file .env.docker config | grep published` — one line is the correct answer.
- **`name: apartment`** is pinned at the top of `docker-compose.yml`. Without it Compose derives the project name from the directory, and networks/volumes (`apartment_pgdata`, `apartment_edge`, …) could clash with the neighbouring app. Never remove it.
- **`APP_URL` must agree with `APP_PORT`.** They drifted apart once (`APP_URL` on 8080, published port 8090); Laravel builds absolute URLs from `APP_URL`, so the mismatch is silent until a generated link 404s.

Local dev has the same constraint — the other app occupies `:8000`, so run this one with `php artisan serve --port=8010`.

Gotchas baked into these files:

- **The images are PHP 8.4, not 8.3.** `composer.lock` resolved Symfony 8 components requiring `php >= 8.4`, so `composer install` hard-fails on 8.3 despite `composer.json` declaring `^8.3`.
- **Assets are built in the PHP stage, not a separate Node stage.** The Wayfinder Vite plugin shells out to `php artisan wayfinder:generate` during `npm run build`, so the asset build needs PHP + `vendor/` + a dumped autoloader present.
- `CONTAINER_ROLE=cli` bypasses the entrypoint's APP_KEY check and DB wait — needed for bootstrap commands, since the guard would otherwise block key generation.
- `opcache.validate_timestamps=0`: code changes require an image rebuild, never a bare restart.

## Stack and conventions

- PHP 8.3, **Laravel 13**, Inertia 3 + Vue 3 (`<script setup lang="ts">`), Tailwind 4 (via `@tailwindcss/vite`, no `tailwind.config`), Vite 8.
- **Postgres** in Docker (the deployment target); SQLite (`database/database.sqlite`) only for Herd-based local dev, and `sqlite::memory:` for tests. Write migrations and queries portable across both, or standardise on Postgres locally too. Queue, cache, and sessions all use the `database` driver — so the queue worker is required for any background scraping.
- Pest 5 is installed, but `tests/Pest.php` is empty and existing tests are PHPUnit-style classes extending `Tests\TestCase`. Either style works; if you want Pest's `test()` syntax with app bootstrapping, wire `uses(Tests\TestCase::class)` in `tests/Pest.php` first.
- PHPStan runs at **level 7** over `app/`, `config/`, `database/`, `routes/`, `bootstrap/app.php` — annotate generics and avoid `mixed` leaks or CI fails.
- Laravel 13 uses the slim skeleton: middleware, exceptions, and scheduling are configured in `bootstrap/app.php`, and console scheduling lives in `routes/console.php` (there is no `app/Console/Kernel.php` or `app/Http/Kernel.php`).

### Wayfinder — generated frontend code

`resources/js/actions/`, `resources/js/routes/`, and `resources/js/wayfinder/` are **generated by `laravel/wayfinder`** from PHP routes/controllers at Vite build time. Never hand-edit them; add or change a PHP route/controller and re-run `npm run dev`/`npm run build`. Import typed route helpers from these modules in Vue instead of writing URL strings.

### Inertia pages

Pages live in `resources/js/pages/` and are referenced by name (`Route::inertia('/', 'Welcome')`). Shared props for every page go through `App\Http\Middleware\HandleInertiaRequests`. The root Blade template is `resources/views/app.blade.php`. Fonts are injected by `laravel-vite-plugin/fonts` (Instrument Sans via Bunny) in `vite.config.ts`.

## CI

`.github/workflows/tests.yml` runs `composer setup` then `composer ci:check` on PHP 8.3 / Node 22 for pushes to `main` and all PRs.
