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
 * @property int $price_from_grosze
 * @property int $price_to_grosze
 * @property CarbonImmutable $observed_at
 */
class PriceChangeRecord extends Model
{
    protected $table = 'price_changes';

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
            'price_from_grosze' => 'integer',
            'price_to_grosze' => 'integer',
            'observed_at' => 'immutable_datetime',
        ];
    }
}
