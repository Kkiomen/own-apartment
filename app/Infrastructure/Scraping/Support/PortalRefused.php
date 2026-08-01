<?php

declare(strict_types=1);

namespace App\Infrastructure\Scraping\Support;

use RuntimeException;

/**
 * The portal turned us away — rate limited, forbidden, or shedding load.
 *
 * Distinct from a generic failure on purpose: this is the one error where
 * carrying on with the remaining pages makes things actively worse, so the
 * caller needs to be able to tell it apart and back off.
 */
final class PortalRefused extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
