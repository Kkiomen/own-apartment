<?php

declare(strict_types=1);

namespace App\Domain\Listing\Appraisal;

/**
 * Why a listing is (or is not) a find.
 *
 * Weights encode one specific situation: a first mortgage, taken as small as
 * possible, on a flat that will not swallow another 100 000 zł in renovation.
 * That is why a low total price outranks a low price per m², and why "needs
 * renovation" costs almost as much as a bargain price gains — a cheap flat you
 * have to gut is not cheap.
 *
 * Change the weights here and the whole ranking follows; nothing else knows
 * about them.
 */
enum ListingLabel: string
{
    case BelowComfortableBudget = 'below_comfortable_budget';
    case CheapPerMeter = 'cheap_per_meter';
    case MoveInReady = 'move_in_ready';
    case PriceDropped = 'price_dropped';
    case LongOnMarket = 'long_on_market';
    case PrivateSeller = 'private_seller';
    case NeedsRenovation = 'needs_renovation';
    case CheaperElsewhere = 'cheaper_elsewhere';

    public function weight(): int
    {
        return match ($this) {
            // The smaller the loan, the better — this is the whole point.
            self::BelowComfortableBudget => 30,
            self::CheapPerMeter => 25,
            // Money not spent on renovation is money not borrowed.
            self::MoveInReady => 20,
            // A seller who already cut the price will probably cut it again.
            self::PriceDropped => 15,
            self::LongOnMarket => 10,
            self::PrivateSeller => 5,
            self::NeedsRenovation => -25,
            self::CheaperElsewhere => -5,
        };
    }

    public function tone(): string
    {
        return $this->weight() >= 0 ? 'good' : 'bad';
    }

    public function isHighlight(): bool
    {
        return $this === self::BelowComfortableBudget || $this === self::CheapPerMeter;
    }
}
