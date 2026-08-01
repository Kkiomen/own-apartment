<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Listing\Listing;
use App\Domain\Listing\Port\PriceHistoryRepository;
use App\Domain\Listing\PriceChange;
use App\Infrastructure\Persistence\Eloquent\Models\PriceChangeRecord;
use RuntimeException;

final class EloquentPriceHistoryRepository implements PriceHistoryRepository
{
    public function record(Listing $listing, PriceChange $change): void
    {
        $listingId = $listing->id();

        if ($listingId === null) {
            throw new RuntimeException('Cannot record a price change for an unsaved listing.');
        }

        PriceChangeRecord::query()->create([
            'listing_id' => $listingId,
            'portal' => $change->observedOn->value,
            'price_from_grosze' => $change->from->grosze,
            'price_to_grosze' => $change->to->grosze,
            'observed_at' => $change->observedAt,
        ]);
    }
}
