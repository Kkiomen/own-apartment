<?php

declare(strict_types=1);

namespace App\Domain\Listing\Port;

use App\Domain\Listing\Fingerprint;
use App\Domain\Listing\Listing;
use App\Domain\Listing\ValueObject\Portal;

interface ListingRepository
{
    /**
     * Primary identity: this exact advert on this exact portal. Stable across
     * price changes, which is why it is tried before the fingerprint.
     */
    public function findBySource(Portal $portal, string $externalId): ?Listing;

    /**
     * Secondary identity: the same flat already known from another portal.
     */
    public function findByFingerprint(Fingerprint $fingerprint): ?Listing;

    public function save(Listing $listing): void;
}
