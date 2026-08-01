<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('listing_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            $table->string('portal', 32);
            $table->string('external_id');
            $table->text('url');
            $table->string('title');

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            // One advert exists once. This is the primary identity used to find
            // a known listing, and the guard against double-inserting it when
            // two scans overlap.
            $table->unique(['portal', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_sources');
    }
};
