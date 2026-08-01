<?php

declare(strict_types=1);

namespace App\Domain\Listing\Read;

/**
 * A listing as the browse screen needs it: already formatted, no domain objects
 * to serialise. Keeping the read model separate from the Listing aggregate
 * means the screen can change without touching the scraping logic.
 */
final readonly class ListingSummary
{
    /**
     * @param  list<string>  $images
     * @param  list<array{portal: string, label: string, url: string, adverts: int}>  $sources
     * @param  list<array{from: string, to: string, at: string, percent: float}>  $priceHistory
     * @param  list<array{label: string, text: string, tone: string}>  $labels
     */
    public function __construct(
        public int $id,
        public string $title,
        public ?string $description,
        public string $city,
        public ?string $district,
        public float $areaM2,
        public string $area,
        public int $rooms,
        public ?int $floor,
        public int $priceZloty,
        public string $price,
        public string $pricePerMeter,
        public ?string $imageUrl,
        public array $images,
        public array $sources,
        public string $firstSeenAt,
        public string $lastSeenAt,
        public ?string $publishedAt,
        public ?bool $isPrivateSeller,
        public array $priceHistory,
        public ?int $totalPriceChangeZloty,
        public ?string $warning,
        public int $score,
        public array $labels,
    ) {}
}
