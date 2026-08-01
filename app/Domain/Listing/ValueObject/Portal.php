<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

/**
 * A listing portal we scrape. Adding one here is step 1 of 2; step 2 is a
 * ListingProvider adapter in Infrastructure\Scraping bound in the container.
 */
enum Portal: string
{
    case Olx = 'olx';
    case Otodom = 'otodom';
    case Gratka = 'gratka';
    case Morizon = 'morizon';
    case NieruchomosciOnline = 'nieruchomosci-online';

    public function label(): string
    {
        return match ($this) {
            self::Olx => 'OLX',
            self::Otodom => 'Otodom',
            self::Gratka => 'Gratka',
            self::Morizon => 'Morizon',
            self::NieruchomosciOnline => 'Nieruchomości-online',
        };
    }

    public function baseUrl(): string
    {
        return match ($this) {
            self::Olx => 'https://www.olx.pl',
            self::Otodom => 'https://www.otodom.pl',
            self::Gratka => 'https://gratka.pl',
            self::Morizon => 'https://www.morizon.pl',
            self::NieruchomosciOnline => 'https://www.nieruchomosci-online.pl',
        };
    }
}
