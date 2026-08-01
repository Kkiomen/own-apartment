<?php

declare(strict_types=1);

use App\Domain\Listing\ValueObject\Area;

it('compares against a minimum', function (): void {
    $minimum = Area::fromSquareMeters(50);

    expect(Area::fromSquareMeters(50)->isAtLeast($minimum))->toBeTrue()
        ->and(Area::fromSquareMeters(49.99)->isAtLeast($minimum))->toBeFalse()
        ->and(Area::fromSquareMeters(56.5)->isAtLeast($minimum))->toBeTrue();
});

it('formats without trailing zeros', function (float $m2, string $expected): void {
    expect(Area::fromSquareMeters($m2)->format())->toBe($expected);
})->with([
    [56.0, '56 m²'],
    [52.5, '52,5 m²'],
    [52.25, '52,25 m²'],
]);

it('rejects areas that cannot be a flat', function (float $m2): void {
    // A parser that grabs the plot area or drops a decimal point produces these.
    // Failing loudly beats storing a 5000 m² "flat".
    Area::fromSquareMeters($m2);
})->with([[0], [-10], [5000]])->throws(InvalidArgumentException::class);
