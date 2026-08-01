<?php

declare(strict_types=1);

namespace App\Domain\Listing\Port;

use App\Domain\Listing\Appraisal\Appraisal;
use App\Domain\Listing\Appraisal\AppraisalSubject;
use App\Domain\Listing\Appraisal\MarketSnapshot;

/**
 * Everything the appraisal pass needs from storage, and nothing else.
 */
interface ListingAppraisals
{
    public function marketSnapshot(): MarketSnapshot;

    /**
     * Streamed rather than returned as an array: this walks every active
     * listing, and there are thousands.
     *
     * @return iterable<AppraisalSubject>
     */
    public function subjects(): iterable;

    public function store(int $listingId, Appraisal $appraisal): void;
}
