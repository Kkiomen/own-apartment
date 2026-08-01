<?php

declare(strict_types=1);

namespace App\Domain\Listing;

enum PlausibilityWarning: string
{
    case PricePerMeterTooLow = 'price_per_meter_too_low';

    public function label(): string
    {
        return match ($this) {
            self::PricePerMeterTooLow => 'Podejrzanie tanio za m² — sprawdź, czy to na pewno całe mieszkanie',
        };
    }
}
