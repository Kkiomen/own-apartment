<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Domain\Listing\Listing;
use App\Domain\Listing\SourceReference;
use App\Domain\Listing\ValueObject\Area;
use App\Domain\Listing\ValueObject\City;
use App\Domain\Listing\ValueObject\Portal;
use App\Domain\Listing\ValueObject\Price;
use App\Infrastructure\Persistence\Eloquent\Models\ListingRecord;
use App\Infrastructure\Persistence\Eloquent\Models\ListingSourceRecord;
use DateTimeImmutable;

/**
 * The translation layer between Eloquent rows and domain objects.
 *
 * Kept separate from the repository (SRP): the repository decides *what* to
 * load, this decides *how* a row becomes a Listing.
 */
final class ListingMapper
{
    public function toDomain(ListingRecord $record): Listing
    {
        return Listing::reconstitute(
            id: $record->id,
            city: City::from($record->city),
            area: Area::fromSquareMeters($record->area_m2),
            rooms: $record->rooms,
            price: Price::fromGrosze($record->price_grosze),
            title: $record->title,
            description: $record->description,
            district: $record->district,
            floor: $record->floor,
            images: array_values(array_filter($record->images ?? [], 'is_string')),
            sources: $this->sourcesToDomain($record),
            firstSeenAt: DateTimeImmutable::createFromInterface($record->first_seen_at),
            lastSeenAt: DateTimeImmutable::createFromInterface($record->last_seen_at),
            isActive: $record->is_active,
            publishedAt: $record->published_at === null ? null : DateTimeImmutable::createFromInterface($record->published_at),
            isPrivateSeller: $record->is_private_seller,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Listing $listing): array
    {
        return [
            'fingerprint' => $listing->fingerprint()->value,
            'variant_key' => $listing->variantKey(),
            'city' => $listing->city()->value,
            'district' => $listing->district(),
            'area_m2' => $listing->area()->squareMeters,
            'rooms' => $listing->rooms(),
            'floor' => $listing->floor(),
            'price_grosze' => $listing->price()->grosze,
            'title' => $listing->title(),
            'description' => $listing->description(),
            'images' => $listing->images(),
            'first_seen_at' => $listing->firstSeenAt(),
            'last_seen_at' => $listing->lastSeenAt(),
            'is_active' => $listing->isActive(),
            'published_at' => $listing->publishedAt(),
            'is_private_seller' => $listing->isPrivateSeller(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sourceToAttributes(SourceReference $source): array
    {
        return [
            'url' => $source->url,
            'title' => $source->title,
            'first_seen_at' => $source->firstSeenAt,
            'last_seen_at' => $source->lastSeenAt,
            'is_active' => $source->isActive,
        ];
    }

    /**
     * @return list<SourceReference>
     */
    private function sourcesToDomain(ListingRecord $record): array
    {
        return array_values($record->sources
            ->map(fn (ListingSourceRecord $source): SourceReference => new SourceReference(
                portal: Portal::from($source->portal),
                externalId: $source->external_id,
                url: $source->url,
                title: $source->title,
                firstSeenAt: DateTimeImmutable::createFromInterface($source->first_seen_at),
                lastSeenAt: DateTimeImmutable::createFromInterface($source->last_seen_at),
                isActive: $source->is_active,
            ))
            ->all());
    }
}
