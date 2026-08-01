<?php

declare(strict_types=1);

namespace App\Domain\Listing\Appraisal;

use App\Domain\Listing\ListingPlausibility;
use App\Domain\Listing\ValueObject\Price;
use DateTimeImmutable;

/**
 * Decides what stands out about a listing and how it ranks.
 *
 * Every label carries the number that earned it, because "okazja" on its own is
 * not information — "18% poniżej mediany w mieście" is something you can argue
 * with.
 */
final readonly class ListingAppraiser
{
    public function __construct(
        private Price $comfortableBudget,
        private float $cheapPerMeterThresholdPercent,
        private int $longOnMarketDays,
        private float $tooGoodToBeTruePercent,
        private ListingPlausibility $plausibility,
    ) {}

    public static function with(
        int $comfortableBudgetZloty,
        float $cheapPerMeterThresholdPercent,
        int $longOnMarketDays,
        float $tooGoodToBeTruePercent,
        ListingPlausibility $plausibility,
    ): self {
        return new self(
            Price::fromZloty($comfortableBudgetZloty),
            $cheapPerMeterThresholdPercent,
            $longOnMarketDays,
            $tooGoodToBeTruePercent,
            $plausibility,
        );
    }

    public function appraise(AppraisalSubject $subject, MarketSnapshot $market, DateTimeImmutable $now): Appraisal
    {
        $found = [];

        if (! $subject->price->isGreaterThan($this->comfortableBudget)) {
            $found[] = [
                ListingLabel::BelowComfortableBudget,
                'Poniżej '.$this->comfortableBudget->format(),
            ];
        }

        $deviation = $market->deviationPercent($subject->city, $subject->pricePerSquareMeter());

        // A price per m² far under the market is only a bargain if it is real.
        // Two guards, because one is not enough: an absolute floor catches
        // nonsense like 1 549 zł/m², and a floor relative to the city median
        // catches the rest — 3 777 zł/m² is a fine price in Tomaszów and an
        // impossible one in Wrocław, so no single absolute number works.
        //
        // Below the relative floor a listing is a share in a property, an
        // auction, or a parsing error. It stays on the list with its warning,
        // but it is not advertised as a find.
        $implausible = $this->plausibility->warningFor($subject->price, $subject->area) !== null
            || ($deviation !== null && $deviation <= -$this->tooGoodToBeTruePercent);

        if (! $implausible && $deviation !== null && $deviation <= -$this->cheapPerMeterThresholdPercent) {
            $found[] = [
                ListingLabel::CheapPerMeter,
                sprintf('%s%% poniżej mediany m² w mieście', number_format(abs($deviation), 0, ',', ' ')),
            ];
        }

        $condition = FlatCondition::of($subject->title, $subject->description);

        if ($condition === ListingLabel::MoveInReady) {
            $found[] = [$condition, 'Gotowe do zamieszkania'];
        }

        if ($condition === ListingLabel::NeedsRenovation) {
            $found[] = [$condition, 'Do remontu — doliczcie koszt'];
        }

        if ($subject->droppedBy !== null) {
            $found[] = [ListingLabel::PriceDropped, 'Cena spadła o '.$subject->droppedBy->format()];
        }

        $daysListed = $this->daysListed($subject->publishedAt, $now);

        if ($daysListed !== null && $daysListed >= $this->longOnMarketDays) {
            $found[] = [ListingLabel::LongOnMarket, "Wisi {$daysListed} dni — jest pole do negocjacji"];
        }

        if ($subject->isPrivateSeller === true) {
            $found[] = [ListingLabel::PrivateSeller, 'Prywatnie, bez prowizji'];
        }

        if ($subject->cheapestElsewhere !== null && $subject->cheapestElsewhere->isLessThan($subject->price)) {
            $difference = Price::fromGrosze($subject->price->grosze - $subject->cheapestElsewhere->grosze);

            $found[] = [
                ListingLabel::CheaperElsewhere,
                'To samo mieszkanie taniej o '.$difference->format(),
            ];
        }

        return Appraisal::of($found);
    }

    private function daysListed(?DateTimeImmutable $publishedAt, DateTimeImmutable $now): ?int
    {
        if ($publishedAt === null) {
            return null;
        }

        return max(0, (int) $publishedAt->diff($now)->days);
    }
}
