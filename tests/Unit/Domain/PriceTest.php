<?php

declare(strict_types=1);

use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\Price;

it('keeps exact values at the edge of our budget', function (): void {
    // The whole reason Price is integer grosze: a float round-trip can turn
    // 599 900 into 599 899.99, and a flat one grosz over budget silently
    // disappears from the list.
    $price = Price::fromZloty(599_900);

    expect($price->grosze)->toBe(59_990_000)
        ->and($price->zloty())->toBe(599_900.0);
});

it('rounds fractional zloty to the nearest grosz', function (): void {
    expect(Price::fromZloty(1234.567)->grosze)->toBe(123_457);
});

it('compares prices', function (): void {
    $budget = Price::fromZloty(600_000);

    expect(Price::fromZloty(599_999)->isGreaterThan($budget))->toBeFalse()
        ->and(Price::fromZloty(600_001)->isGreaterThan($budget))->toBeTrue()
        ->and(Price::fromZloty(600_000)->isGreaterThan($budget))->toBeFalse()
        ->and(Price::fromZloty(600_000)->equals($budget))->toBeTrue();
});

it('reports the signed difference to another price', function (): void {
    $before = Price::fromZloty(550_000);
    $after = Price::fromZloty(520_000);

    expect($before->differenceTo($after))->toBe(-3_000_000);
});

it('calculates price per square meter', function (): void {
    $perMeter = Price::fromZloty(560_000)->per(Area::fromSquareMeters(56));

    expect($perMeter->zloty())->toBe(10_000.0);
});

it('formats for humans', function (): void {
    expect(Price::fromZloty(549_000)->format())->toBe('549 000 zł');
});

it('refuses negative prices', function (): void {
    Price::fromGrosze(-1);
})->throws(InvalidArgumentException::class);
