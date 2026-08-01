<?php

declare(strict_types=1);

use App\Domain\Listing\ListingPlausibility;
use App\Domain\Listing\PlausibilityWarning;
use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\Price;

beforeEach(function (): void {
    $this->plausibility = ListingPlausibility::fromZloty(3_000);
});

it('flags a listing that is far too cheap per square meter to be a flat', function (): void {
    // Straight from the live data: 226 m² in Wrocław for 350 000 zł is
    // 1 549 zł/m² — a share in a property or a plot, not somewhere to live.
    $warning = $this->plausibility->warningFor(Price::fromZloty(350_000), Area::fromSquareMeters(226));

    expect($warning)->toBe(PlausibilityWarning::PricePerMeterTooLow);
});

it('leaves an ordinary flat alone', function (): void {
    $warning = $this->plausibility->warningFor(Price::fromZloty(549_000), Area::fromSquareMeters(56));

    expect($warning)->toBeNull();
});

it('treats the threshold itself as plausible', function (): void {
    $warning = $this->plausibility->warningFor(Price::fromZloty(150_000), Area::fromSquareMeters(50));

    expect($warning)->toBeNull();
});

it('has a message that says what to do about it', function (): void {
    expect(PlausibilityWarning::PricePerMeterTooLow->label())->toContain('sprawdź');
});
