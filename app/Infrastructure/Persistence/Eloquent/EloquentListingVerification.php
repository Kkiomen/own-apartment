<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Listing\Port\ListingVerification;
use App\Domain\Listing\SourceReference;
use App\Domain\Listing\ValueObject\Portal;
use App\Infrastructure\Persistence\Eloquent\Models\ListingSourceRecord;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentListingVerification implements ListingVerification
{
    public function __construct(private ListingMapper $mapper) {}

    public function dueForVerification(DateTimeImmutable $notCheckedSince, int $limit): array
    {
        return array_values(ListingSourceRecord::query()
            ->where('is_active', true)
            ->where(static fn (Builder $query): Builder => $query
                ->whereNull('last_verified_at')
                ->orWhere('last_verified_at', '<', $notCheckedSince))
            // Never-checked first, then oldest check. `is not null` rather than
            // NULLS FIRST because that syntax is Postgres-only and the tests
            // (and local dev) run on SQLite.
            ->orderByRaw('last_verified_at is not null')
            ->orderBy('last_verified_at')
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->map(fn (ListingSourceRecord $source): SourceReference => $this->mapper->sourceToDomain($source))
            ->all());
    }

    public function recordChecked(Portal $portal, string $externalId, DateTimeImmutable $at): void
    {
        $this->source($portal, $externalId)->update(['last_verified_at' => $at]);
    }

    public function recordGone(Portal $portal, string $externalId, DateTimeImmutable $at): bool
    {
        return $this->source($portal, $externalId)
            ->where('is_active', true)
            ->update(['last_verified_at' => $at, 'is_active' => false]) > 0;
    }

    /**
     * @return Builder<ListingSourceRecord>
     */
    private function source(Portal $portal, string $externalId): Builder
    {
        return ListingSourceRecord::query()
            ->where('portal', $portal->value)
            ->where('external_id', $externalId);
    }
}
