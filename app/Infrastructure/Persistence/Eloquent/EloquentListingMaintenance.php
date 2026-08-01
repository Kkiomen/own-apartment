<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Listing\Port\ListingMaintenance;
use App\Domain\Listing\ValueObject\Portal;
use App\Infrastructure\Persistence\Eloquent\Models\ListingRecord;
use App\Infrastructure\Persistence\Eloquent\Models\ListingSourceRecord;
use DateTimeImmutable;

final class EloquentListingMaintenance implements ListingMaintenance
{
    public function deactivateSourcesNotSeenSince(Portal $portal, DateTimeImmutable $cutoff): int
    {
        return ListingSourceRecord::query()
            ->where('portal', $portal->value)
            ->where('is_active', true)
            ->where('last_seen_at', '<', $cutoff)
            ->update(['is_active' => false]);
    }

    public function deactivateListingsWithoutActiveSources(): int
    {
        return ListingRecord::query()
            ->where('is_active', true)
            ->whereNotExists(fn ($query) => $query
                ->selectRaw('1')
                ->from('listing_sources')
                ->whereColumn('listing_sources.listing_id', 'listings.id')
                ->where('listing_sources.is_active', true))
            ->update(['is_active' => false]);
    }
}
