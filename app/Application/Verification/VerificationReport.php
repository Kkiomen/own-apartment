<?php

declare(strict_types=1);

namespace App\Application\Verification;

use App\Domain\Listing\ValueObject\Portal;

final class VerificationReport
{
    private int $checked = 0;

    private int $available = 0;

    private int $gone = 0;

    private int $unreachable = 0;

    private int $skipped = 0;

    private int $galleriesFilled = 0;

    private int $retiredListings = 0;

    /** @var list<string> */
    private array $refusedPortals = [];

    public function recordAvailable(): void
    {
        $this->checked++;
        $this->available++;
    }

    public function recordGone(): void
    {
        $this->checked++;
        $this->gone++;
    }

    public function recordUnreachable(): void
    {
        $this->checked++;
        $this->unreachable++;
    }

    /** An advert whose offer page gave us more photos than the search page had. */
    public function recordGalleryFilled(): void
    {
        $this->galleriesFilled++;
    }

    public function galleriesFilled(): int
    {
        return $this->galleriesFilled;
    }

    /** An advert we never asked about, because its portal had already refused us. */
    public function recordSkipped(): void
    {
        $this->skipped++;
    }

    public function recordRefusal(Portal $portal): void
    {
        if (! in_array($portal->value, $this->refusedPortals, true)) {
            $this->refusedPortals[] = $portal->value;
        }
    }

    public function recordRetiredListings(int $count): void
    {
        $this->retiredListings = $count;
    }

    public function checked(): int
    {
        return $this->checked;
    }

    public function available(): int
    {
        return $this->available;
    }

    public function gone(): int
    {
        return $this->gone;
    }

    public function unreachable(): int
    {
        return $this->unreachable;
    }

    public function skipped(): int
    {
        return $this->skipped;
    }

    public function retiredListings(): int
    {
        return $this->retiredListings;
    }

    /** @return list<string> */
    public function refusedPortals(): array
    {
        return $this->refusedPortals;
    }
}
