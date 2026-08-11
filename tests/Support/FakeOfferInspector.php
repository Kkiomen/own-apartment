<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Listing\Availability;
use App\Domain\Listing\OfferSnapshot;
use App\Domain\Listing\Port\OfferInspector;
use App\Domain\Listing\SourceReference;

/**
 * Answers whatever the test says, per advert, and remembers what it was asked.
 * The "asked" part is the point of several tests: not knocking on a portal that
 * has just refused us is the behaviour under test.
 */
final class FakeOfferInspector implements OfferInspector
{
    /** @var array<string, OfferSnapshot> */
    private array $answers = [];

    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly Availability $default = Availability::Available) {}

    public function answer(string $externalId, Availability $availability): self
    {
        $this->answers[$externalId] = new OfferSnapshot($availability);

        return $this;
    }

    /**
     * @param  list<string>  $images
     */
    public function gallery(string $externalId, array $images): self
    {
        $this->answers[$externalId] = new OfferSnapshot(Availability::Available, $images);

        return $this;
    }

    public function inspect(SourceReference $source): OfferSnapshot
    {
        $this->asked[] = $source->externalId;

        return $this->answers[$source->externalId] ?? new OfferSnapshot($this->default);
    }
}
