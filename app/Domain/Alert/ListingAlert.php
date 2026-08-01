<?php

declare(strict_types=1);

namespace App\Domain\Alert;

use App\Domain\Listing\Listing;
use App\Domain\Listing\PriceChange;

/**
 * Something worth interrupting someone's day for.
 *
 * Only two things qualify, deliberately: a flat that matches the criteria and
 * we have never seen before, and a price drop on a flat we are already
 * tracking. Everything else belongs in the app, not in a notification.
 */
final readonly class ListingAlert
{
    private function __construct(
        public AlertReason $reason,
        public Listing $listing,
        public ?PriceChange $priceChange = null,
    ) {}

    public static function newMatch(Listing $listing): self
    {
        return new self(AlertReason::NewMatch, $listing);
    }

    public static function priceDrop(Listing $listing, PriceChange $change): self
    {
        return new self(AlertReason::PriceDrop, $listing, $change);
    }
}
