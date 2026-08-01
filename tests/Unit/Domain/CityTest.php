<?php

declare(strict_types=1);

use App\Domain\Listing\ValueObject\City;

it('resolves cities from the messy strings portals actually print', function (string $printed, City $expected): void {
    expect(City::tryFromName($printed))->toBe($expected);
})->with([
    'plain' => ['Wrocław', City::Wroclaw],
    'no diacritics' => ['Wroclaw', City::Wroclaw],
    'with voivodeship' => ['Warszawa, mazowieckie', City::Warszawa],
    'lowercase' => ['łódź', City::Lodz],
    'city with district' => ['Wrocław, Krzyki, Gaj', City::Wroclaw],
    'english name' => ['Warsaw', City::Warszawa],
]);

it('does not mistake Tomaszów Mazowiecki for Łódź', function (string $printed): void {
    // Tomaszów Mazowiecki is IN the łódzkie voivodeship, so portals render it
    // as "Tomaszów Mazowiecki, łódzkie". A naive substring match on "lodz"
    // files every one of these under Łódź and the flats vanish from the city
    // we are actually searching.
    expect(City::tryFromName($printed))->toBe(City::TomaszowMazowiecki);
})->with([
    ['Tomaszów Mazowiecki, łódzkie'],
    ['Tomaszów Mazowiecki, tomaszowski, łódzkie'],
    ['tomaszow mazowiecki'],
]);

it('does not match the voivodeship adjective as a city', function (): void {
    // "łódzkie" is the region, not the city. Matching it would pull in every
    // small town in central Poland.
    expect(City::tryFromName('Rawa Mazowiecka, łódzkie'))->toBeNull();
});

it('returns null for cities we do not want rather than guessing', function (string $printed): void {
    expect(City::tryFromName($printed))->toBeNull();
})->with([
    ['Kraków'],
    ['Poznań, wielkopolskie'],
    [''],
    ['   '],
]);

it('has a human label for every case', function (City $city): void {
    expect($city->label())->not->toBe('');
})->with(City::cases());
