<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table): void {
            $table->id();

            // Cross-portal identity of one physical flat. Recomputed on every
            // price change, so it always reflects current state.
            $table->string('fingerprint')->index();

            $table->string('city', 64);
            $table->string('district')->nullable();
            $table->decimal('area_m2', 6, 2);
            $table->unsignedTinyInteger('rooms');
            $table->smallInteger('floor')->nullable();

            // Grosze, never a float. See Domain\Listing\ValueObject\Price.
            $table->unsignedBigInteger('price_grosze');

            $table->string('title');
            $table->text('image_url')->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // The list view: active flats in a city, cheapest first.
            $table->index(['city', 'is_active', 'price_grosze']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listings');
    }
};
