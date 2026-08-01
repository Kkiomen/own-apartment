<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\Clock;
use DateTimeImmutable;

final class FrozenClock implements Clock
{
    public function __construct(private DateTimeImmutable $now) {}

    public static function at(string $moment): self
    {
        return new self(new DateTimeImmutable($moment));
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function advanceTo(string $moment): void
    {
        $this->now = new DateTimeImmutable($moment);
    }
}
