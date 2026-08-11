<?php

use App\Infrastructure\Console\ScanPortalsCommand;
use App\Infrastructure\Console\VerifyListingsCommand;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Runs in the `scheduler` container (php artisan schedule:work).
|
| Hourly, not every few minutes: flats are not a high-frequency market, and
| hammering five portals is the fastest way to get the VPS IP blocked.
| withoutOverlapping guards against a slow scan colliding with the next tick.
|
| Deliberately not on the hour. :00 is when every cron on the internet fires,
| so it is both the portals' busiest minute and the easiest traffic spike to
| pick a scraper out of.
|
*/

Schedule::command(ScanPortalsCommand::class)
    ->hourlyAt(17)
    ->withoutOverlapping()
    ->runInBackground();

/*
| Verifying adverts one at a time — the other half of retiring, and the only
| way to learn that a flat is gone rather than merely unseen.
|
| Every other hour, and half an hour off the scan: a sweep of five portals can
| run for a good while, and two streams of requests going to the same portal at
| once is exactly the pattern the search sweep is otherwise so careful to avoid.
| The run itself is small — max_per_run requests, ~90 seconds at the usual delay.
*/

Schedule::command(VerifyListingsCommand::class)
    ->cron('47 */2 * * *')
    ->withoutOverlapping()
    ->runInBackground();
