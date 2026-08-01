<?php

declare(strict_types=1);

use App\Domain\Listing\Appraisal\Appraisal;
use App\Domain\Listing\Appraisal\AppraisalSubject;
use App\Domain\Listing\Appraisal\ListingAppraiser;
use App\Domain\Listing\Appraisal\ListingLabel;
use App\Domain\Listing\Appraisal\MarketSnapshot;
use App\Domain\Listing\ListingPlausibility;
use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Price;

$now = new DateTimeImmutable('2026-08-01 12:00:00');

beforeEach(function (): void {
    $this->appraiser = ListingAppraiser::with(
        comfortableBudgetZloty: 500_000,
        cheapPerMeterThresholdPercent: 10,
        longOnMarketDays: 60,
        tooGoodToBeTruePercent: 40,
        plausibility: ListingPlausibility::fromZloty(3_000),
    );

    // 10 000 zł/m² in Wrocław.
    $this->market = new MarketSnapshot(['wroclaw' => 1_000_000]);

    $this->subject = fn (array $overrides = []): AppraisalSubject => new AppraisalSubject(
        listingId: $overrides['id'] ?? 1,
        city: City::Wroclaw,
        area: Area::fromSquareMeters($overrides['area'] ?? 55.0),
        price: Price::fromZloty($overrides['price'] ?? 560_000),
        title: $overrides['title'] ?? 'Mieszkanie 3 pokoje',
        description: $overrides['description'] ?? null,
        publishedAt: $overrides['publishedAt'] ?? null,
        isPrivateSeller: $overrides['isPrivateSeller'] ?? null,
        droppedBy: $overrides['droppedBy'] ?? null,
        cheapestElsewhere: $overrides['cheapestElsewhere'] ?? null,
    );
});

function labelsOf(Appraisal $appraisal): array
{
    return array_column($appraisal->labels, 'label');
}

it('labels a flat inside the comfortable budget', function () use ($now): void {
    // The whole point: a smaller mortgage. This is the strongest single signal.
    $appraisal = $this->appraiser->appraise(($this->subject)(['price' => 480_000]), $this->market, $now);

    expect(labelsOf($appraisal))->toContain(ListingLabel::BelowComfortableBudget->value)
        ->and($appraisal->labels[0]['text'])->toBe('Poniżej 500 000 zł');
});

it('does not label a flat one złoty over the budget', function () use ($now): void {
    $appraisal = $this->appraiser->appraise(($this->subject)(['price' => 500_001]), $this->market, $now);

    expect(labelsOf($appraisal))->not->toContain(ListingLabel::BelowComfortableBudget->value);
});

it('says how far below the city median a bargain sits', function () use ($now): void {
    // 55 m² at 440 000 zł is 8 000 zł/m², i.e. 20% under a 10 000 median.
    $appraisal = $this->appraiser->appraise(($this->subject)(['price' => 440_000]), $this->market, $now);

    $cheap = collect($appraisal->labels)->firstWhere('label', ListingLabel::CheapPerMeter->value);

    expect($cheap)->not->toBeNull()
        ->and($cheap['text'])->toBe('20% poniżej mediany m² w mieście');
});

it('ignores a price per meter only slightly under the median', function () use ($now): void {
    // 5% below is noise, not a find.
    $appraisal = $this->appraiser->appraise(($this->subject)(['price' => 522_500]), $this->market, $now);

    expect(labelsOf($appraisal))->not->toContain(ListingLabel::CheapPerMeter->value);
});

it('penalises a flat that needs renovation', function () use ($now): void {
    // A cheap flat you have to gut is not cheap — that is the whole reason
    // this label costs almost as much as a bargain price earns.
    $needsWork = $this->appraiser->appraise(
        ($this->subject)(['price' => 480_000, 'description' => 'Mieszkanie do generalnego remontu.']),
        $this->market, $now,
    );

    $ready = $this->appraiser->appraise(
        ($this->subject)(['price' => 480_000, 'description' => 'Mieszkanie po remoncie, gotowe do zamieszkania.']),
        $this->market, $now,
    );

    expect(labelsOf($needsWork))->toContain(ListingLabel::NeedsRenovation->value)
        ->and(labelsOf($ready))->toContain(ListingLabel::MoveInReady->value)
        ->and($ready->score)->toBeGreaterThan($needsWork->score);
});

it('flags the same flat being cheaper on another portal', function () use ($now): void {
    $appraisal = $this->appraiser->appraise(
        ($this->subject)(['price' => 560_000, 'cheapestElsewhere' => Price::fromZloty(529_000)]),
        $this->market, $now,
    );

    $label = collect($appraisal->labels)->firstWhere('label', ListingLabel::CheaperElsewhere->value);

    expect($label['text'])->toBe('To samo mieszkanie taniej o 31 000 zł');
});

it('does not flag a flat that is the cheapest of its variants', function () use ($now): void {
    $appraisal = $this->appraiser->appraise(
        ($this->subject)(['price' => 529_000, 'cheapestElsewhere' => Price::fromZloty(529_000)]),
        $this->market, $now,
    );

    expect(labelsOf($appraisal))->not->toContain(ListingLabel::CheaperElsewhere->value);
});

it('notices a flat that has been sitting on the market', function () use ($now): void {
    $appraisal = $this->appraiser->appraise(
        ($this->subject)(['publishedAt' => new DateTimeImmutable('2026-05-01 12:00:00')]),
        $this->market, $now,
    );

    $label = collect($appraisal->labels)->firstWhere('label', ListingLabel::LongOnMarket->value);

    expect($label['text'])->toBe('Wisi 92 dni — jest pole do negocjacji');
});

it('ranks a cheap move-in-ready flat above an expensive one needing work', function () use ($now): void {
    $find = $this->appraiser->appraise(
        ($this->subject)([
            'price' => 440_000,
            'description' => 'Po remoncie, gotowe do zamieszkania.',
            'isPrivateSeller' => true,
        ]),
        $this->market, $now,
    );

    $dud = $this->appraiser->appraise(
        ($this->subject)(['price' => 595_000, 'description' => 'Mieszkanie do remontu.']),
        $this->market, $now,
    );

    expect($find->score)->toBeGreaterThan($dud->score)
        ->and($dud->score)->toBeLessThan(0);
});

it('refuses to call an implausible price a bargain', function () use ($now): void {
    // Straight from the live data: 226 m² in Wrocław for 350 000 zł is
    // 1 549 zł/m², i.e. 85% under the median. That is not the deal of the
    // decade, it is a share in a property or a mis-parsed area — and without
    // this guard it topped the "best offers" list.
    $appraisal = $this->appraiser->appraise(
        ($this->subject)(['price' => 350_000, 'area' => 226.0]),
        $this->market, $now,
    );

    expect(labelsOf($appraisal))->not->toContain(ListingLabel::CheapPerMeter->value)
        // Still cheap in absolute terms, so that label stays.
        ->and(labelsOf($appraisal))->toContain(ListingLabel::BelowComfortableBudget->value);
});

it('refuses a price that is impossible for the city, even if plausible elsewhere', function () use ($now): void {
    // Live data again: 83,4 m² in Wrocław for 314 990 zł is 3 777 zł/m². That
    // clears the absolute floor — it would be an ordinary price in Tomaszów —
    // but it is 63% under the Wrocław median, which no normal flat is.
    $appraisal = $this->appraiser->appraise(
        ($this->subject)(['price' => 314_990, 'area' => 83.4]),
        $this->market, $now,
    );

    expect(labelsOf($appraisal))->not->toContain(ListingLabel::CheapPerMeter->value);
});

it('still calls a genuinely good price a bargain', function () use ($now): void {
    // 25% under the median: excellent, and entirely believable.
    $appraisal = $this->appraiser->appraise(
        ($this->subject)(['price' => 412_500, 'area' => 55.0]),
        $this->market, $now,
    );

    expect(labelsOf($appraisal))->toContain(ListingLabel::CheapPerMeter->value);
});

it('scores an unremarkable listing at zero', function () use ($now): void {
    $appraisal = $this->appraiser->appraise(($this->subject)(), $this->market, $now);

    expect($appraisal->score)->toBe(0)
        ->and($appraisal->labels)->toBe([]);
});
