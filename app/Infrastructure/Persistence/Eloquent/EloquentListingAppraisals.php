<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Listing\Appraisal\Appraisal;
use App\Domain\Listing\Appraisal\AppraisalSubject;
use App\Domain\Listing\Appraisal\MarketSnapshot;
use App\Domain\Listing\Port\ListingAppraisals;
use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Price;
use App\Infrastructure\Persistence\Eloquent\Models\ListingRecord;
use App\Infrastructure\Persistence\Eloquent\Models\PriceChangeRecord;
use DateTimeImmutable;
use Generator;
use Illuminate\Support\Facades\DB;

final class EloquentListingAppraisals implements ListingAppraisals
{
    public function marketSnapshot(): MarketSnapshot
    {
        $medians = [];

        foreach (City::cases() as $city) {
            $perMeter = ListingRecord::query()
                ->where('city', $city->value)
                ->where('is_active', true)
                ->orderByRaw('price_grosze / area_m2')
                ->pluck(DB::raw('price_grosze / area_m2 as per_meter'))
                ->map(static fn (mixed $value): float => (float) $value)
                ->all();

            if ($perMeter === []) {
                continue;
            }

            // True median: with an even count, the midpoint of the two middle
            // values. Averaging the whole set would let one 1 549 zł/m² oddity
            // drag the benchmark down and make overpriced flats look normal.
            $count = count($perMeter);
            $middle = intdiv($count, 2);

            $medians[$city->value] = (int) round($count % 2 === 1
                ? $perMeter[$middle]
                : ($perMeter[$middle - 1] + $perMeter[$middle]) / 2);
        }

        return new MarketSnapshot($medians);
    }

    public function subjects(): Generator
    {
        $cheapestByVariant = $this->cheapestPricePerVariant();
        $dropsByListing = $this->totalDropPerListing();

        foreach (ListingRecord::query()->where('is_active', true)->lazyById(200) as $record) {
            $cheapest = $cheapestByVariant[$record->variant_key] ?? null;

            yield new AppraisalSubject(
                listingId: $record->id,
                city: City::from($record->city),
                area: Area::fromSquareMeters($record->area_m2),
                price: Price::fromGrosze($record->price_grosze),
                title: $record->title,
                description: $record->description,
                publishedAt: $record->published_at === null
                    ? null
                    : DateTimeImmutable::createFromInterface($record->published_at),
                isPrivateSeller: $record->is_private_seller,
                droppedBy: isset($dropsByListing[$record->id])
                    ? Price::fromGrosze($dropsByListing[$record->id])
                    : null,
                cheapestElsewhere: $cheapest === null || $cheapest >= $record->price_grosze
                    ? null
                    : Price::fromGrosze($cheapest),
            );
        }
    }

    public function store(int $listingId, Appraisal $appraisal): void
    {
        ListingRecord::query()->whereKey($listingId)->update([
            'score' => $appraisal->score,
            'labels' => $appraisal->labels,
        ]);
    }

    /**
     * @return array<string, int> variant key => cheapest asking price in grosze
     */
    private function cheapestPricePerVariant(): array
    {
        return ListingRecord::query()
            ->where('is_active', true)
            ->whereNotNull('variant_key')
            ->groupBy('variant_key')
            ->selectRaw('variant_key, min(price_grosze) as cheapest')
            ->pluck('cheapest', 'variant_key')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }

    /**
     * @return array<int, int> listing id => total drop in grosze, positive
     */
    private function totalDropPerListing(): array
    {
        return PriceChangeRecord::query()
            ->whereColumn('price_to_grosze', '<', 'price_from_grosze')
            ->groupBy('listing_id')
            ->selectRaw('listing_id, sum(price_from_grosze - price_to_grosze) as dropped')
            ->pluck('dropped', 'listing_id')
            ->map(static fn (mixed $value): int => (int) $value)
            ->all();
    }
}
