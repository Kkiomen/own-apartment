<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Domain\Listing\ValueObject\Portal;
use DateTimeImmutable;

/**
 * Where one physical flat is advertised. A Listing has one of these per portal
 * it was found on.
 */
final readonly class SourceReference
{
    public function __construct(
        public Portal $portal,
        public string $externalId,
        public string $url,
        public string $title,
        public DateTimeImmutable $firstSeenAt,
        public DateTimeImmutable $lastSeenAt,
        public bool $isActive = true,
    ) {}

    public function seenAt(DateTimeImmutable $moment): self
    {
        return new self(
            portal: $this->portal,
            externalId: $this->externalId,
            url: $this->url,
            title: $this->title,
            firstSeenAt: $this->firstSeenAt,
            lastSeenAt: $moment,
            isActive: true,
        );
    }

    public function matches(Portal $portal, string $externalId): bool
    {
        return $this->portal === $portal && $this->externalId === $externalId;
    }
}
