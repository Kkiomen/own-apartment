<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Persistence detail, not a domain object.
 *
 * Nothing outside Infrastructure\Persistence may touch this class — the domain
 * speaks App\Domain\Listing\Listing, and the mapper translates.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $city
 * @property string|null $district
 * @property float $area_m2
 * @property int $rooms
 * @property int|null $floor
 * @property int $price_grosze
 * @property string $title
 * @property string|null $description
 * @property list<string>|null $images
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property CarbonImmutable|null $published_at
 * @property bool|null $is_private_seller
 * @property bool $is_active
 * @property string|null $variant_key
 * @property int $score
 * @property list<array{label: string, text: string, tone: string}>|null $labels
 * @property-read Collection<int, ListingSourceRecord> $sources
 */
class ListingRecord extends Model
{
    protected $table = 'listings';

    protected $guarded = [];

    /**
     * @return HasMany<ListingSourceRecord, $this>
     */
    public function sources(): HasMany
    {
        return $this->hasMany(ListingSourceRecord::class, 'listing_id');
    }

    /**
     * @return HasMany<PriceChangeRecord, $this>
     */
    public function priceChanges(): HasMany
    {
        return $this->hasMany(PriceChangeRecord::class, 'listing_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'area_m2' => 'float',
            'rooms' => 'integer',
            'floor' => 'integer',
            'price_grosze' => 'integer',
            'images' => 'array',
            'labels' => 'array',
            'score' => 'integer',
            'is_active' => 'boolean',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'published_at' => 'immutable_datetime',
            'is_private_seller' => 'boolean',
        ];
    }
}
