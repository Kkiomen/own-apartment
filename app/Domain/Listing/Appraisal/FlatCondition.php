<?php

declare(strict_types=1);

namespace App\Domain\Listing\Appraisal;

/**
 * Reads the advert for what it says about the state of the flat.
 *
 * Crude by nature — it is keyword matching over marketing copy — but the
 * distinction it draws is the expensive one: "do remontu" and "stan
 * deweloperski" both mean tens of thousands of złoty on top of the price, and
 * for a first mortgage that is the difference between affordable and not.
 */
final class FlatCondition
{
    /** Phrases that mean money still has to go in. */
    private const NEEDS_WORK = [
        'do remontu',
        'do generalnego remontu',
        'do kapitalnego remontu',
        'do odswiezenia',
        'do wykonczenia',
        'stan deweloperski',
        'stan surowy',
        'wymaga remontu',
    ];

    /** Phrases that mean you could move in with a suitcase. */
    private const READY = [
        'po remoncie',
        'po generalnym remoncie',
        'gotowe do zamieszkania',
        'gotowy do zamieszkania',
        'pod klucz',
        'w pelni wykonczone',
        'wykonczone pod klucz',
        'nowe wykonczenie',
        'odnowione',
        'wyremontowane',
    ];

    public static function of(?string ...$texts): ?ListingLabel
    {
        $haystack = self::normalize(implode(' ', array_filter($texts, is_string(...))));

        if ($haystack === '') {
            return null;
        }

        // "Po remoncie, kuchnia do odświeżenia" describes a flat that is
        // essentially done. An explicit claim of being renovated is stronger
        // evidence than a passing mention of touching something up, so it wins.
        if (self::mentions($haystack, self::READY)) {
            return ListingLabel::MoveInReady;
        }

        return self::mentions($haystack, self::NEEDS_WORK) ? ListingLabel::NeedsRenovation : null;
    }

    /**
     * @param  list<string>  $phrases
     */
    private static function mentions(string $haystack, array $phrases): bool
    {
        foreach ($phrases as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower($value);

        $value = strtr($value, [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
        ]);

        return trim((string) preg_replace('/\s+/', ' ', $value));
    }
}
