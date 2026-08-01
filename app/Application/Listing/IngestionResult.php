<?php

declare(strict_types=1);

namespace App\Application\Listing;

use App\Domain\Listing\Listing;
use App\Domain\Listing\PriceChange;

final readonly class IngestionResult
{
    /**
     * @param  list<string>  $rejectionReasons
     */
    private function __construct(
        public IngestionOutcome $outcome,
        public ?Listing $listing = null,
        public ?PriceChange $priceChange = null,
        public array $rejectionReasons = [],
    ) {}

    public static function created(Listing $listing): self
    {
        return new self(IngestionOutcome::Created, $listing);
    }

    public static function unchanged(Listing $listing): self
    {
        return new self(IngestionOutcome::Unchanged, $listing);
    }

    public static function priceChanged(Listing $listing, PriceChange $change): self
    {
        return new self(IngestionOutcome::PriceChanged, $listing, $change);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function rejected(array $reasons): self
    {
        return new self(IngestionOutcome::Rejected, rejectionReasons: $reasons);
    }
}
