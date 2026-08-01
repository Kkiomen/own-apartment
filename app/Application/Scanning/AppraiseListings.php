<?php

declare(strict_types=1);

namespace App\Application\Scanning;

use App\Domain\Listing\Appraisal\ListingAppraiser;
use App\Domain\Listing\Port\ListingAppraisals;
use App\Domain\Shared\Clock;

/**
 * Re-ranks every listing after a scan.
 *
 * Runs as a whole-set pass rather than per listing because the benchmarks are
 * relative: what counts as cheap per m² depends on the median of everything
 * else we are tracking, and that moves with every scan.
 */
final readonly class AppraiseListings
{
    public function __construct(
        private ListingAppraisals $appraisals,
        private ListingAppraiser $appraiser,
        private Clock $clock,
    ) {}

    public function __invoke(): int
    {
        $market = $this->appraisals->marketSnapshot();
        $now = $this->clock->now();
        $appraised = 0;

        foreach ($this->appraisals->subjects() as $subject) {
            $this->appraisals->store(
                $subject->listingId,
                $this->appraiser->appraise($subject, $market, $now),
            );

            $appraised++;
        }

        return $appraised;
    }
}
