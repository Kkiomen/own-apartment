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
*/

Schedule::command(ScanPortalsCommand::class)
    ->hourly()
    ->withoutOverlapping()
    ->runInBackground();
