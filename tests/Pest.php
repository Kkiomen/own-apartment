<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Feature tests
|--------------------------------------------------------------------------
|
| Boot the framework and get a clean database. Unit tests deliberately do NOT
| extend TestCase: the domain and application layers must run without Laravel,
| and a test that accidentally needs the container should fail loudly.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        // Scrapers must never touch the network from a test. A stray request
        // fails the suite instead of quietly making it slow, flaky and
        // dependent on a portal's uptime.
        Http::preventStrayRequests();
    })
    ->in('Feature');
