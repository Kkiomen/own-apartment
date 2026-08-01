<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $listing_id
 * @property string $portal
 * @property string $external_id
 * @property string $url
 * @property string $title
 * @property CarbonImmutable $first_seen_at
 * @property CarbonImmutable $last_seen_at
 * @property bool $is_active
 */
class ListingSourceRecord extends Model
{
    protected $table = 'listing_sources';

    protected $guarded = [];

    /**
     * @return BelongsTo<ListingRecord, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(ListingRecord::class, 'listing_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }
}
