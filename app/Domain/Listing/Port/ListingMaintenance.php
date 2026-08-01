<?php

declare(strict_types=1);

namespace App\Domain\Listing\Port;

use App\Domain\Listing\ValueObject\Portal;
use DateTimeImmutable;

/**
 * Retiring listings that are gone.
 *
 * Separate port (ISP): this is a bulk housekeeping concern with nothing in
 * common with loading or saving an aggregate.
 */
interface ListingMaintenance
{
    /**
     * Mark this portal's adverts inactive when the portal stopped showing them.
     *
     * Scoped to one portal on purpose: it may only be called for a portal whose
     * scan actually succeeded, otherwise a portal being down for a few days
     * would retire every flat we know about.
     *
     * @return int sources deactivated
     */
    public function deactivateSourcesNotSeenSince(Portal $portal, DateTimeImmutable $cutoff): int;

    /**
     * A flat is gone once no portal advertises it any more.
     *
     * @return int listings deactivated
     */
    public function deactivateListingsWithoutActiveSources(): int;
}
