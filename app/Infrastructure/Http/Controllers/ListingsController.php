<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controllers;

use App\Domain\Listing\Port\ListingCatalog;
use App\Domain\Listing\Read\ListingFilter;
use App\Domain\Listing\Read\SortOrder;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use App\Domain\Search\SearchCriteria;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Driving adapter: turns a browser request into a catalog query.
 *
 * No business logic — reads query parameters, hands them to the port, renders.
 */
final class ListingsController
{
    public function __invoke(Request $request, ListingCatalog $catalog, SearchCriteria $criteria): Response
    {
        $filter = new ListingFilter(
            city: City::tryFrom((string) $request->query('city', '')),
            portal: Portal::tryFrom((string) $request->query('portal', '')),
            // Best offers first by default: the point of the app is to surface
            // the few worth looking at, not to reproduce the portals' feeds.
            sort: SortOrder::tryFrom((string) $request->query('sort', '')) ?? SortOrder::BestValue,
            page: max(1, (int) $request->query('page', 1)),
            onlyPriceDrops: $request->boolean('drops'),
            minPrice: $this->price($request->query('priceFrom')),
            maxPrice: $this->price($request->query('priceTo')),
        );

        $page = $catalog->browse($filter);

        return Inertia::render('Listings', [
            'listings' => $page->items,
            'pagination' => [
                'page' => $page->page,
                'perPage' => $page->perPage,
                'total' => $page->total,
                'lastPage' => $page->lastPage(),
            ],
            'filters' => [
                'city' => $filter->city?->value,
                'portal' => $filter->portal?->value,
                'sort' => $filter->sort->value,
                'drops' => $filter->onlyPriceDrops,
                'priceFrom' => $filter->minPrice === null ? null : (int) $filter->minPrice->zloty(),
                'priceTo' => $filter->maxPrice === null ? null : (int) $filter->maxPrice->zloty(),
            ],
            'options' => [
                'cities' => array_map(
                    static fn (City $city): array => ['value' => $city->value, 'label' => $city->label()],
                    City::cases(),
                ),
                'portals' => array_map(
                    static fn (Portal $portal): array => ['value' => $portal->value, 'label' => $portal->label()],
                    Portal::cases(),
                ),
                'sorts' => array_map(
                    static fn (SortOrder $sort): array => ['value' => $sort->value, 'label' => $sort->label()],
                    SortOrder::cases(),
                ),
            ],
            'criteria' => [
                'minArea' => $criteria->minArea->format(),
                'minRooms' => $criteria->minRooms,
                'minPrice' => $criteria->minPrice->format(),
                'maxPrice' => $criteria->maxPrice->format(),
            ],
            'stats' => $catalog->stats(),
        ]);
    }

    /**
     * A price in whole złoty from the query string, or null when absent or
     * nonsense. Zero counts as absent — an empty input must not filter out
     * every listing.
     */
    private function price(mixed $raw): ?Price
    {
        if (! is_string($raw) && ! is_int($raw)) {
            return null;
        }

        $zloty = (int) $raw;

        return $zloty > 0 ? Price::fromZloty($zloty) : null;
    }
}
