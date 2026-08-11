<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Listing\Port\ListingMaintenance;
use App\Domain\Listing\Port\ListingVerification;
use App\Domain\Listing\SourceReference;
use App\Domain\Listing\ValueObject\Portal;
use DateTimeImmutable;

/**
 * In-memory driven adapter for the verification queue.
 *
 * Doubles as the ListingMaintenance side, because in this use case the two are
 * asked one question between them — "did anything actually go?" — and a second
 * fake would only be bookkeeping.
 */
final class InMemoryListingVerification implements ListingMaintenance, ListingVerification
{
    /** @var list<SourceReference> */
    private array $sources = [];

    /** @var array<string, DateTimeImmutable> */
    private array $checkedAt = [];

    public int $listingsRetired = 0;

    public function add(SourceReference ...$sources): void
    {
        foreach ($sources as $source) {
            $this->sources[] = $source;
        }
    }

    public function dueForVerification(DateTimeImmutable $notCheckedSince, int $limit): array
    {
        $due = array_filter($this->sources, function (SourceReference $source) use ($notCheckedSince): bool {
            $checked = $this->checkedAt[$this->key($source->portal, $source->externalId)] ?? null;

            return $source->isActive && ($checked === null || $checked < $notCheckedSince);
        });

        return array_slice(array_values($due), 0, $limit);
    }

    public function recordChecked(Portal $portal, string $externalId, DateTimeImmutable $at): void
    {
        $this->checkedAt[$this->key($portal, $externalId)] = $at;
    }

    public function recordGone(Portal $portal, string $externalId, DateTimeImmutable $at): bool
    {
        $this->recordChecked($portal, $externalId, $at);

        $deactivated = false;

        foreach ($this->sources as $index => $source) {
            if ($source->matches($portal, $externalId) && $source->isActive) {
                $this->sources[$index] = new SourceReference(
                    portal: $source->portal,
                    externalId: $source->externalId,
                    url: $source->url,
                    title: $source->title,
                    firstSeenAt: $source->firstSeenAt,
                    lastSeenAt: $source->lastSeenAt,
                    isActive: false,
                );

                $deactivated = true;
            }
        }

        return $deactivated;
    }

    public function deactivateSourcesNotSeenSince(Portal $portal, DateTimeImmutable $cutoff): int
    {
        return 0;
    }

    public function deactivateListingsWithoutActiveSources(): int
    {
        return $this->listingsRetired;
    }

    public function isActive(Portal $portal, string $externalId): bool
    {
        foreach ($this->sources as $source) {
            if ($source->matches($portal, $externalId)) {
                return $source->isActive;
            }
        }

        return false;
    }

    public function checkedAt(Portal $portal, string $externalId): ?DateTimeImmutable
    {
        return $this->checkedAt[$this->key($portal, $externalId)] ?? null;
    }

    private function key(Portal $portal, string $externalId): string
    {
        return "{$portal->value}|{$externalId}";
    }
}
