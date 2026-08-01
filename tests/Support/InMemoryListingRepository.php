<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Listing\Fingerprint;
use App\Domain\Listing\Listing;
use App\Domain\Listing\Port\ListingRepository;
use App\Domain\Listing\ValueObject\Portal;

/**
 * In-memory driven adapter. Lets the use cases be tested with no database,
 * no container and no migrations — which is the payoff for defining the port.
 */
final class InMemoryListingRepository implements ListingRepository
{
    /** @var array<int, Listing> */
    private array $listings = [];

    private int $nextId = 1;

    public function findBySource(Portal $portal, string $externalId): ?Listing
    {
        foreach ($this->listings as $listing) {
            foreach ($listing->sources() as $source) {
                if ($source->matches($portal, $externalId)) {
                    return $listing;
                }
            }
        }

        return null;
    }

    public function findByFingerprint(Fingerprint $fingerprint): ?Listing
    {
        foreach ($this->listings as $listing) {
            if ($listing->fingerprint()->equals($fingerprint)) {
                return $listing;
            }
        }

        return null;
    }

    public function save(Listing $listing): void
    {
        if ($listing->id() === null) {
            $listing->assignId($this->nextId++);
        }

        /** @var int $id */
        $id = $listing->id();

        $this->listings[$id] = $listing;
    }

    /**
     * @return list<Listing>
     */
    public function all(): array
    {
        return array_values($this->listings);
    }

    public function count(): int
    {
        return count($this->listings);
    }
}
