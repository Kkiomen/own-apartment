<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Listing\ListingPlausibility;
use App\Domain\Listing\Port\ListingCatalog;
use App\Domain\Listing\Read\ListingFilter;
use App\Domain\Listing\Read\ListingSummary;
use App\Domain\Listing\Read\ListingSummaryPage;
use App\Domain\Listing\Read\SortOrder;
use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use App\Infrastructure\Persistence\Eloquent\Models\ListingRecord;
use App\Infrastructure\Persistence\Eloquent\Models\ListingSourceRecord;
use App\Infrastructure\Persistence\Eloquent\Models\PriceChangeRecord;
use Illuminate\Database\Eloquent\Builder;

final readonly class EloquentListingCatalog implements ListingCatalog
{
    public function __construct(private ListingPlausibility $plausibility) {}

    public function browse(ListingFilter $filter): ListingSummaryPage
    {
        $query = $this->baseQuery($filter);

        $total = (clone $query)->count();

        $records = $query
            ->with([
                'sources' => static fn ($relation) => $relation->orderBy('first_seen_at')->orderBy('id'),
                // Newest first, tie-broken by insertion order: two changes can
                // land in the same second and must still render in the order
                // they actually happened.
                'priceChanges' => static fn ($relation) => $relation->orderByDesc('observed_at')->orderByDesc('id'),
            ])
            ->forPage($filter->page, $filter->perPage)
            ->get();

        $items = $records
            ->map(fn (ListingRecord $record): ListingSummary => $this->toSummary($record))
            ->all();

        return new ListingSummaryPage(
            items: array_values($items),
            total: $total,
            page: $filter->page,
            perPage: $filter->perPage,
        );
    }

    public function stats(): array
    {
        /** @var array<string, int> $byCity */
        $byCity = ListingRecord::query()
            ->where('is_active', true)
            ->groupBy('city')
            ->selectRaw('city, count(*) as aggregate')
            ->pluck('aggregate', 'city')
            ->map(static fn (mixed $count): int => (int) $count)
            ->all();

        return [
            'total' => ListingRecord::query()->where('is_active', true)->count(),
            'byCity' => $byCity,
            'withPriceDrop' => $this->priceDropQuery()->count(),
        ];
    }

    /**
     * @return Builder<ListingRecord>
     */
    private function baseQuery(ListingFilter $filter): Builder
    {
        $query = ListingRecord::query()->where('is_active', true);

        if ($filter->city !== null) {
            $query->where('city', $filter->city->value);
        }

        if ($filter->portal !== null) {
            $query->whereIn('id', ListingSourceRecord::query()
                ->select('listing_id')
                ->where('portal', $filter->portal->value));
        }

        if ($filter->minPrice !== null) {
            $query->where('price_grosze', '>=', $filter->minPrice->grosze);
        }

        if ($filter->maxPrice !== null) {
            $query->where('price_grosze', '<=', $filter->maxPrice->grosze);
        }

        if ($filter->onlyPriceDrops) {
            $query->whereIn('id', $this->priceDropQuery()->select('listing_id'));
        }

        return match ($filter->sort) {
            // Ties broken by price per m², so among equally labelled flats the
            // better deal still comes first.
            SortOrder::BestValue => $query
                ->orderByDesc('score')
                ->orderByRaw('price_grosze / area_m2 asc'),
            // The portal's own publication date, with unknown dates last.
            //
            // Two traps here, both hit in practice. Sorting on first_seen_at
            // is meaningless — every listing from one scan shares a timestamp,
            // so the order collapses into "whichever portal ran last". And
            // coalescing the two is worse: a listing with no date gets today's
            // scan time and outranks a genuinely newer advert published in June.
            //
            // Gratka, Morizon and Nieruchomości-online publish no date, so they
            // sort after everything dated. That is honest — we cannot claim
            // they are new — and the other sort orders treat them equally.
            SortOrder::Newest => $query
                ->orderByRaw('published_at is null')
                ->orderByDesc('published_at')
                ->orderByDesc('first_seen_at')
                ->orderByDesc('id'),
            SortOrder::PriceAsc => $query->orderBy('price_grosze'),
            SortOrder::PriceDesc => $query->orderByDesc('price_grosze'),
            // SQLite and Postgres both do integer division here, so the ratio is
            // computed in grosze to keep enough precision to sort by.
            SortOrder::PricePerMeter => $query->orderByRaw('price_grosze / area_m2 asc'),
            SortOrder::AreaDesc => $query->orderByDesc('area_m2'),
        };
    }

    /**
     * @return Builder<PriceChangeRecord>
     */
    private function priceDropQuery(): Builder
    {
        return PriceChangeRecord::query()->whereColumn('price_to_grosze', '<', 'price_from_grosze');
    }

    private function toSummary(ListingRecord $record): ListingSummary
    {
        $area = Area::fromSquareMeters($record->area_m2);
        $price = Price::fromGrosze($record->price_grosze);

        $drops = $record->priceChanges
            ->filter(static fn (PriceChangeRecord $c): bool => $c->price_to_grosze < $c->price_from_grosze);

        $images = array_values(array_filter($record->images ?? [], 'is_string'));

        return new ListingSummary(
            id: $record->id,
            title: $record->title,
            description: $record->description,
            city: City::from($record->city)->label(),
            district: $record->district,
            areaM2: $record->area_m2,
            area: $area->format(),
            rooms: $record->rooms,
            floor: $record->floor,
            priceZloty: (int) $price->zloty(),
            price: $price->format(),
            pricePerMeter: $price->per($area)->format().'/m²',
            imageUrl: $images[0] ?? null,
            images: $images,
            sources: $this->sources($record),
            firstSeenAt: $record->first_seen_at->toIso8601String(),
            lastSeenAt: $record->last_seen_at->toIso8601String(),
            publishedAt: $record->published_at?->toIso8601String(),
            isPrivateSeller: $record->is_private_seller,
            priceHistory: $this->priceHistory($record),
            totalPriceChangeZloty: $drops->isEmpty()
                ? null
                : (int) round($drops->sum(static fn (PriceChangeRecord $c): int => $c->price_to_grosze - $c->price_from_grosze) / 100),
            warning: $this->plausibility->warningFor($price, $area)?->label(),
            score: $record->score,
            labels: array_values(array_filter($record->labels ?? [], 'is_array')),
        );
    }

    /**
     * One entry per portal, not per advert.
     *
     * The same flat is often posted twice on one portal by different agents,
     * which would otherwise render as two identical buttons. The duplicate is
     * still counted so it stays visible that it happened.
     *
     * @return list<array{portal: string, label: string, url: string, adverts: int}>
     */
    private function sources(ListingRecord $record): array
    {
        $byPortal = [];

        foreach ($record->sources as $source) {
            $portal = $source->portal;

            if (isset($byPortal[$portal])) {
                $byPortal[$portal]['adverts']++;

                continue;
            }

            $byPortal[$portal] = [
                'portal' => $portal,
                'label' => Portal::from($portal)->label(),
                'url' => $source->url,
                'adverts' => 1,
            ];
        }

        return array_values($byPortal);
    }

    /**
     * Newest first — what a price did recently matters more than its whole life.
     * Ordering is done in the query, see browse().
     *
     * @return list<array{from: string, to: string, at: string, percent: float}>
     */
    private function priceHistory(ListingRecord $record): array
    {
        return array_values($record->priceChanges
            ->map(static function (PriceChangeRecord $change): array {
                $from = Price::fromGrosze($change->price_from_grosze);
                $to = Price::fromGrosze($change->price_to_grosze);

                return [
                    'from' => $from->format(),
                    'to' => $to->format(),
                    'at' => $change->observed_at->toIso8601String(),
                    'percent' => round(($from->differenceTo($to) / $from->grosze) * 100, 1),
                ];
            })
            ->all());
    }
}
