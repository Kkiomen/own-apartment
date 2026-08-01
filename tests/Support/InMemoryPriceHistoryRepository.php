<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Listing\Listing;
use App\Domain\Listing\Port\PriceHistoryRepository;
use App\Domain\Listing\PriceChange;

final class InMemoryPriceHistoryRepository implements PriceHistoryRepository
{
    /** @var list<array{listing: Listing, change: PriceChange}> */
    public array $recorded = [];

    public function record(Listing $listing, PriceChange $change): void
    {
        $this->recorded[] = ['listing' => $listing, 'change' => $change];
    }

    public function count(): int
    {
        return count($this->recorded);
    }
}
