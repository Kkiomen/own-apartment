<?php

declare(strict_types=1);

namespace App\Domain\Listing\Port;

use App\Domain\Listing\SourceReference;
use App\Domain\Listing\ValueObject\Portal;
use DateTimeImmutable;

/**
 * Bookkeeping for re-checking adverts one by one.
 *
 * Distinct from ListingMaintenance, which retires adverts in bulk on the
 * evidence of a *search sweep* ("the portal stopped listing it"). This port
 * serves the opposite evidence: the offer page itself, fetched directly.
 */
interface ListingVerification
{
    /**
     * Active adverts not checked since the cutoff, oldest check first.
     *
     * Never-checked adverts come first, so a fresh deployment works through its
     * backlog instead of re-checking the same handful for ever.
     *
     * @return list<SourceReference>
     */
    public function dueForVerification(DateTimeImmutable $notCheckedSince, int $limit): array;

    /** The advert is still up — remember when we looked, so it goes to the back of the queue. */
    public function recordChecked(Portal $portal, string $externalId, DateTimeImmutable $at): void;

    /**
     * The portal says the advert is gone. Deactivates it.
     *
     * @return bool whether this call was the one that deactivated it
     */
    public function recordGone(Portal $portal, string $externalId, DateTimeImmutable $at): bool;
}
