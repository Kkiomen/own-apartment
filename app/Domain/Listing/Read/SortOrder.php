<?php

declare(strict_types=1);

namespace App\Domain\Listing\Read;

enum SortOrder: string
{
    case BestValue = 'best_value';
    case Newest = 'newest';
    case PriceAsc = 'price_asc';
    case PriceDesc = 'price_desc';
    case PricePerMeter = 'price_per_meter';
    case AreaDesc = 'area_desc';

    public function label(): string
    {
        return match ($this) {
            self::BestValue => 'Najlepsze okazje',
            self::Newest => 'Najnowsze',
            self::PriceAsc => 'Cena rosnąco',
            self::PriceDesc => 'Cena malejąco',
            self::PricePerMeter => 'Cena za m²',
            self::AreaDesc => 'Metraż malejąco',
        };
    }
}
