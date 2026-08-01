<?php

declare(strict_types=1);

namespace App\Application\Listing;

enum IngestionOutcome: string
{
    /** Matched the criteria and we had never seen it — this is the interesting one. */
    case Created = 'created';

    /** Already known, still there, price unchanged. */
    case Unchanged = 'unchanged';

    /** Already known, asking price moved. */
    case PriceChanged = 'price_changed';

    /** Scraped fine, but it is not a flat we want. */
    case Rejected = 'rejected';
}
