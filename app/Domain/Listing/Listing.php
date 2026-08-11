<?php

declare(strict_types=1);

namespace App\Domain\Listing;

use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use DateTimeImmutable;

/**
 * One physical flat, however many portals advertise it.
 *
 * The aggregate owns its source references and is the only place that decides
 * whether a sighting counts as a price change.
 */
final class Listing implements PropertyAttributes
{
    /**
     * @param  list<SourceReference>  $sources
     * @param  list<string>  $images
     */
    private function __construct(
        private ?int $id,
        private readonly City $city,
        private readonly Area $area,
        private readonly int $rooms,
        private Price $price,
        private string $title,
        private ?string $description,
        private ?string $district,
        private ?int $floor,
        private array $images,
        private array $sources,
        private readonly DateTimeImmutable $firstSeenAt,
        private DateTimeImmutable $lastSeenAt,
        private bool $isActive,
        private ?DateTimeImmutable $publishedAt = null,
        private ?bool $isPrivateSeller = null,
    ) {}

    public static function fromFirstSighting(ListingData $data, DateTimeImmutable $now): self
    {
        return new self(
            id: null,
            city: $data->city(),
            area: $data->area(),
            rooms: $data->rooms(),
            price: $data->price(),
            title: $data->title,
            description: $data->description,
            district: $data->district,
            floor: $data->floor,
            images: $data->images,
            sources: [new SourceReference(
                portal: $data->portal,
                externalId: $data->externalId,
                url: $data->url,
                title: $data->title,
                firstSeenAt: $now,
                lastSeenAt: $now,
            )],
            firstSeenAt: $now,
            lastSeenAt: $now,
            isActive: true,
            publishedAt: $data->publishedAt,
            isPrivateSeller: $data->isPrivateSeller,
        );
    }

    /**
     * Rebuild from storage. Only the repository should call this.
     *
     * @param  list<SourceReference>  $sources
     * @param  list<string>  $images
     */
    public static function reconstitute(
        int $id,
        City $city,
        Area $area,
        int $rooms,
        Price $price,
        string $title,
        ?string $description,
        ?string $district,
        ?int $floor,
        array $images,
        array $sources,
        DateTimeImmutable $firstSeenAt,
        DateTimeImmutable $lastSeenAt,
        bool $isActive,
        ?DateTimeImmutable $publishedAt = null,
        ?bool $isPrivateSeller = null,
    ): self {
        return new self($id, $city, $area, $rooms, $price, $title, $description, $district, $floor, $images, $sources, $firstSeenAt, $lastSeenAt, $isActive, $publishedAt, $isPrivateSeller);
    }

    /**
     * Record that a portal showed us this flat again.
     *
     * Returns a PriceChange only when the price actually moved, so callers can
     * treat "something happened" and "nothing happened" differently without
     * comparing prices themselves.
     */
    public function registerSighting(ListingData $data, DateTimeImmutable $now): ?PriceChange
    {
        $this->touchSource($data, $now);

        $this->lastSeenAt = $now;
        $this->isActive = true;

        // Keep the newest wording — agents edit titles, and a stale title in a
        // notification is confusing when you click through.
        $this->title = $data->title;
        $this->district ??= $data->district;
        $this->floor ??= $data->floor;

        // Portals differ in how much they publish. Take whichever sighting
        // actually carried detail rather than letting a terse one erase it.
        //
        // "More" rather than "any": a search page carries only the first three
        // photos of an advert that has thirteen, so a plain overwrite would
        // undo the full gallery read off the offer page on the next scan.
        if (count($data->images) > count($this->images)) {
            $this->images = $data->images;
        }

        if ($data->description !== null && $data->description !== '') {
            $this->description = $data->description;
        }

        // Keep the earliest publication date across portals — the advert that
        // has been up longest is the one that says how stale the offer is.
        if ($data->publishedAt !== null && ($this->publishedAt === null || $data->publishedAt < $this->publishedAt)) {
            $this->publishedAt = $data->publishedAt;
        }

        $this->isPrivateSeller ??= $data->isPrivateSeller;

        if ($data->price()->equals($this->price)) {
            return null;
        }

        $change = new PriceChange(
            from: $this->price,
            to: $data->price(),
            observedOn: $data->portal,
            observedAt: $now,
        );

        $this->price = $data->price();

        return $change;
    }

    private function touchSource(ListingData $data, DateTimeImmutable $now): void
    {
        foreach ($this->sources as $index => $source) {
            if ($source->matches($data->portal, $data->externalId)) {
                $this->sources[$index] = $source->seenAt($now);

                return;
            }
        }

        $this->sources[] = new SourceReference(
            portal: $data->portal,
            externalId: $data->externalId,
            url: $data->url,
            title: $data->title,
            firstSeenAt: $now,
            lastSeenAt: $now,
        );
    }

    /**
     * Computed, never stored as immutable state: it depends on the current
     * price, so it has to move when the price does or cross-portal matching
     * would compare a fresh listing against a stale key.
     */
    public function fingerprint(): Fingerprint
    {
        return Fingerprint::of($this->city, $this->area, $this->rooms, $this->price);
    }

    /**
     * The same flat regardless of what anyone is asking for it.
     *
     * The fingerprint includes price on purpose, so one flat advertised at two
     * prices stays two rows. This key is how those rows find each other again.
     */
    public function variantKey(): string
    {
        return sprintf('%s|%.1f|%d', $this->city->value, $this->area->squareMeters, $this->rooms);
    }

    public function markInactive(): void
    {
        $this->isActive = false;
    }

    public function assignId(int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    public function city(): City
    {
        return $this->city;
    }

    public function area(): Area
    {
        return $this->area;
    }

    public function rooms(): int
    {
        return $this->rooms;
    }

    public function price(): Price
    {
        return $this->price;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function district(): ?string
    {
        return $this->district;
    }

    public function floor(): ?int
    {
        return $this->floor;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    /**
     * @return list<string>
     */
    public function images(): array
    {
        return $this->images;
    }

    /**
     * Take the gallery read off the advert's own page.
     *
     * Only ever an improvement: search results carry the first three photos of
     * a flat, the offer page carries all thirteen. A poorer set is refused
     * rather than trusted, because it is far more likely to be a parser that
     * has stopped finding the gallery than a seller who deleted ten photos.
     *
     * @param  list<string>  $images
     * @return bool whether the gallery changed
     */
    public function refreshGallery(array $images): bool
    {
        if (count($images) <= count($this->images)) {
            return false;
        }

        $this->images = $images;

        return true;
    }

    /**
     * @return list<SourceReference>
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @return list<Portal>
     */
    public function portals(): array
    {
        return array_map(
            static fn (SourceReference $source): Portal => $source->portal,
            $this->sources,
        );
    }

    public function firstSeenAt(): DateTimeImmutable
    {
        return $this->firstSeenAt;
    }

    public function lastSeenAt(): DateTimeImmutable
    {
        return $this->lastSeenAt;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function isPrivateSeller(): ?bool
    {
        return $this->isPrivateSeller;
    }
}
