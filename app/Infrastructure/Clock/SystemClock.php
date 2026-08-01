<?php

declare(strict_types=1);

namespace App\Infrastructure\Clock;

use App\Domain\Shared\Clock;
use DateTimeImmutable;

final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable;
    }
}
