<?php

declare(strict_types=1);

namespace App\Domain\Listing;

/**
 * What one offer page told us when we opened it.
 *
 * Availability and the gallery travel together because they come from a single
 * request. Splitting them into two ports would mean fetching the same page
 * twice, and traffic to a portal is the one budget this project cannot overrun.
 */
final readonly class OfferSnapshot
{
    /**
     * @param  list<string>  $images  empty whenever the page could not be read,
     *                                or when nothing knows how to read that portal's gallery
     */
    public function __construct(
        public Availability $availability,
        public array $images = [],
    ) {}

    public static function unavailable(Availability $availability): self
    {
        return new self($availability);
    }
}
