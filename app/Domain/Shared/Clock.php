<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeImmutable;

/**
 * Time as a port.
 *
 * The domain never calls now() directly — "first seen 3 days ago", "price
 * dropped this week" and stale-listing detection are all time-dependent, and
 * tests need to control time rather than sleep through it.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
