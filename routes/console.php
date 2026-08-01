<?php

use App\Infrastructure\Console\ScanPortalsCommand;
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
