<?php

declare(strict_types=1);

namespace App\Domain\Listing;

/**
 * What the portal answered when we asked whether an advert is still up.
 *
 * The three "not available" cases are kept apart on purpose, because they call
 * for opposite reactions: only Gone is a fact about the flat. Unreachable is a
 * fact about the network, and Refused is a fact about us — it means the portal
 * turned us away and the honest reading is "we do not know", never "sold".
 */
enum Availability
{
    /** The advert is still up. */
    case Available;

    /** The portal says this advert no longer exists. */
    case Gone;

    /** We could not read the page. Says nothing about the advert. */
    case Unreachable;

    /** The portal blocked us (403/429/503). Stop asking it for now. */
    case Refused;
}
