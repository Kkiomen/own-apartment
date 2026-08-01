<?php

declare(strict_types=1);

namespace App\Domain\Listing\ValueObject;

/**
 * The cities we are willing to live in.
 *
 * Portals spell these inconsistently ("Łódź", "Lodz", "Tomaszów Mazowiecki,
 * łódzkie"), so matching goes through self::tryFromName() rather than the
 * backed value.
 */
enum City: string
{
    case Wroclaw = 'wroclaw';
    case Warszawa = 'warszawa';
    case Lodz = 'lodz';
    case TomaszowMazowiecki = 'tomaszow-mazowiecki';

    public function label(): string
    {
        return match ($this) {
            self::Wroclaw => 'Wrocław',
            self::Warszawa => 'Warszawa',
            self::Lodz => 'Łódź',
            self::TomaszowMazowiecki => 'Tomaszów Mazowiecki',
        };
    }

    /**
     * Resolve a city from whatever a portal happened to print.
     *
     * Deliberately conservative: an unrecognised location returns null and the
     * listing is dropped, rather than being silently filed under the wrong city.
     */
    public static function tryFromName(?string $name): ?self
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $normalized = self::normalize($name);

        foreach (self::matchOrder() as $city) {
            foreach ($city->aliases() as $alias) {
                if (preg_match('/\b'.preg_quote($alias, '/').'\b/', $normalized) === 1) {
                    return $city;
                }
            }
        }

        return null;
    }

    /**
     * Most specific first.
     *
     * Tomaszów Mazowiecki sits in the łódzkie voivodeship, so portals render it
     * as "Tomaszów Mazowiecki, łódzkie". Checking Łódź first would file every
     * Tomaszów listing under Łódź.
     *
     * @return list<self>
     */
    private static function matchOrder(): array
    {
        return [self::TomaszowMazowiecki, self::Wroclaw, self::Warszawa, self::Lodz];
    }

    /**
     * Word-boundary matched, which is what keeps the voivodeship adjective
     * "łódzkie" from matching the city "łódź".
     *
     * @return list<string>
     */
    private function aliases(): array
    {
        return match ($this) {
            self::Wroclaw => ['wroclaw'],
            self::Warszawa => ['warszawa', 'warsaw'],
            self::Lodz => ['lodz'],
            self::TomaszowMazowiecki => ['tomaszow mazowiecki', 'tomaszow maz'],
        };
    }

    /**
     * Lowercase, strip Polish diacritics, collapse punctuation to spaces.
     */
    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));

        $value = strtr($value, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', $value));
    }
}
