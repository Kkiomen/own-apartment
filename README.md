<p align="center">
  <img src="public/icon-192.png" width="88" height="88" alt="">
</p>

<h1 align="center">Mieszkania</h1>

<p align="center">
  Samodzielnie hostowany monitor ogłoszeń mieszkań na sprzedaż.<br>
  Scrapuje pięć polskich portali, scala duplikaty i śledzi historię zmian cen.
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.4-777BB4" alt="PHP 8.4">
  <img src="https://img.shields.io/badge/Laravel-13-FF2D20" alt="Laravel 13">
  <img src="https://img.shields.io/badge/Vue-3-42B883" alt="Vue 3">
  <img src="https://img.shields.io/badge/tests-211%20passing-3d4e68" alt="211 testów">
</p>

---

## Po co to jest

Szukanie mieszkania na OLX, Otodomie, Gratce, Morizonie i Nieruchomościach-online oznacza pięć zakładek, pięć zestawów filtrów i te same oferty w kółko — bo jedno mieszkanie potrafi wisieć na czterech portalach naraz, wystawione przez dwóch agentów.

Ta aplikacja robi to raz na godzinę w tle i pokazuje **jedną listę**:

- **Deduplikacja między portalami** — to samo mieszkanie z czterech źródeł to jedna karta z czterema linkami, nie cztery karty.
- **Historia cen** — każda zmiana zapisana ze znacznikiem czasu. Widać, że mieszkanie stoi od pół roku i już dwa razy potaniało, czyli że sprzedający będzie negocjował.
- **Dopasowanie do kryteriów** — metraż, liczba pokoi, budżet i lista miast, wszystko konfigurowalne.
- **Wycofywanie ofert** — ogłoszenie, którego portal przestał pokazywać, znika z listy; jak wróci, wraca też ono.

Domyślne kryteria (`config/search.php`): powyżej 50 m², min. 3 pokoje, do 600 000 zł, w Wrocławiu, Warszawie, Łodzi i Tomaszowie Mazowieckim.

## Obsługiwane portale

| Portal | Format danych | Filtry po stronie portalu |
|---|---|---|
| OLX | `window.__PRERENDERED_STATE__` | działają |
| Otodom | `__NEXT_DATA__` | działają |
| Gratka | `ld+json` (schema.org) | działają |
| Morizon | `ld+json` (schema.org) | działają |
| Nieruchomości-online | `ld+json` (schema.org) | ignorowane przez portal |

Parsery czytają osadzony JSON, a nie selektory CSS — to ten sam JSON, który zasila front-endy portali, więc przeżywa zmiany szaty graficznej. Filtrowanie i tak powtarzane jest po stronie domeny, bo żaden portal nie jest traktowany jako źródło prawdy.

## Szybki start (lokalnie)

Wymagania: PHP 8.4, Composer, Node 22.

```bash
composer setup    # zależności, .env, klucz, migracje, build front-endu
composer dev      # serwer + worker kolejki + Vite naraz
```

Aplikacja stanie pod `http://localhost:8000`. Pierwsze pobranie ogłoszeń:

```bash
php artisan listings:scan
```

> **Uwaga:** jeśli port 8000 jest zajęty, uruchom `php artisan serve --port=8010`.

## Wdrożenie (Docker)

Docelowe środowisko to VPS. Jedna komenda, bezpieczna przy ponownym uruchomieniu:

```bash
./deploy.sh
```

Skrypt tworzy `.env.docker` z szablonu, generuje `APP_KEY` i hasło do bazy **tylko jeśli są jeszcze placeholderami** (świeży `APP_KEY` przy każdym wdrożeniu po cichu wyłogowałby wszystkich), buduje obrazy i podnosi stack.

Aplikacja wystawi się na `http://localhost:8090` (`APP_PORT` w `.env.docker`).

Kontenery:

| Serwis | Rola |
|---|---|
| `web` | nginx — **jedyny** serwis publikujący port na hoście |
| `migrate` | jednorazowe `migrate --force`; reszta czeka na jego sukces |
| `seed` | jednorazowy pierwszy scan, żeby świeże wdrożenie nie było puste |
| `app` | php-fpm |
| `queue` | `queue:work` — tu działają scrapery |
| `scheduler` | `schedule:work` — scan co godzinę |
| `postgres` | bez `ports:`, tylko w sieci `internal: true` — nieosiągalny z hosta i z internetu |

### Kilka aplikacji na jednym VPS

Stack jest na to przygotowany i te własności nie mogą się zepsuć:

- publikowany jest **dokładnie jeden** port hosta (`APP_PORT`) — sprawdzisz przez `docker compose --env-file .env.docker config | grep published`,
- `name: apartment` jest przypięte w `docker-compose.yml`, więc sieci i wolumeny są w przestrzeni nazw `apartment_*`,
- `APP_URL` musi zgadzać się z `APP_PORT` — Laravel buduje z niego linki bezwzględne, a rozjazd jest niewidoczny aż do pierwszego martwego odnośnika.

## Architektura

Porty i adaptery (heksagonalna), z zależnościami wskazującymi **wyłącznie do środka**:

```
app/
  Domain/          encje, value objects, interfejsy portów — czysty PHP,
                   bez Illuminate\*, bez Eloquenta
  Application/     przypadki użycia (IngestListing, ScanPortals)
  Infrastructure/  adaptery: scrapery, Eloquent, powiadomienia, HTTP, komendy
```

Granica jest pilnowana mechanicznie, nie dyscypliną — test architektury Pest sprawdza, że `App\Domain` nie importuje frameworka.

Dodanie portalu to jedna klasa `PortalSearch`, jeden parser i jedno powiązanie w `DomainServiceProvider`. Paginacja, uprzejmość wobec serwerów i limity stron nie są implementowane od nowa dla każdego portalu.

## Testy

```bash
composer test        # Pint → PHPStan (level 7) → 211 testów
composer ci:check    # to, co uruchamia CI: ESLint, Prettier, vue-tsc + powyższe
```

Scrapery **nigdy nie chodzą po sieci w testach** — parsują zapisane odpowiedzi z `tests/Fixtures/`, a `Http::preventStrayRequests()` sprawia, że przypadkowe zapytanie na żywo wywala suite głośno, zamiast po cichu czynić testy wolnymi i niestabilnymi.

## Konfiguracja

| Plik | Do czego |
|---|---|
| `config/search.php` | kryteria, miasta, limity stron, uprzejmość scrapowania |
| `.env` | lokalny development (Herd/SQLite) |
| `.env.docker` | kontenery (Postgres) — celowo osobny, żeby oba środowiska się nie biły |

Żaden z plików `.env` nie trafia do repozytorium.

## Status

Aplikacja jest kompletna w swoim zakresie. Jedyna świadomie zostawiona luka: **nie jest podpięty żaden kanał powiadomień** — `AlertNotifier` ma implementację `LoggingAlertNotifier`, która pisze do logu. Podpięcie Telegrama czy e-maila to jedna klasa i jedno powiązanie w service providerze.

## Zastrzeżenia

Prywatny, niekomercyjny projekt na własny użytek. Scrapowanie wykonywane jest z pauzami między zapytaniami i twardym limitem stron; przed użyciem sprawdź regulaminy portali. Dane ogłoszeń należą do ich właścicieli.
