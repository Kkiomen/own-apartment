<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Listing\Fingerprint;
use App\Domain\Listing\Listing;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\Portal;
use App\Infrastructure\Persistence\Eloquent\Models\ListingRecord;
use App\Infrastructure\Persistence\Eloquent\Models\ListingSourceRecord;
use Illuminate\Support\Facades\DB;

final readonly class EloquentListingRepository implements ListingRepository
{
    public function __construct(private ListingMapper $mapper) {}

    public function findBySource(Portal $portal, string $externalId): ?Listing
    {
        $source = ListingSourceRecord::query()
            ->where('portal', $portal->value)
            ->where('external_id', $externalId)
            ->first();

        if ($source === null) {
            return null;
        }

        $listing = ListingRecord::query()->with('sources')->find($source->listing_id);

        return $listing === null ? null : $this->mapper->toDomain($listing);
    }

    public function findByFingerprint(Fingerprint $fingerprint): ?Listing
    {
        $record = ListingRecord::query()
            ->with('sources')
            ->where('fingerprint', $fingerprint->value)
            ->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function save(Listing $listing): void
    {
        DB::transaction(function () use ($listing): void {
            $record = $listing->id() !== null
                ? ListingRecord::query()->findOrFail($listing->id())
                : new ListingRecord;

            $record->forceFill($this->mapper->toAttributes($listing))->save();

            if ($listing->id() === null) {
                $listing->assignId($record->id);
            }

            $this->syncSources($listing, $record->id);
        });
    }

    private function syncSources(Listing $listing, int $listingId): void
    {
        foreach ($listing->sources() as $source) {
            ListingSourceRecord::query()->updateOrCreate(
                // Matches the unique index, so two overlapping scans converge
                // on one row instead of racing to insert duplicates.
                [
                    'portal' => $source->portal->value,
                    'external_id' => $source->externalId,
                ],
                $this->mapper->sourceToAttributes($source) + ['listing_id' => $listingId],
            );
        }
    }
}
