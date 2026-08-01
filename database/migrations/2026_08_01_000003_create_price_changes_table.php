<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_changes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();

            $table->string('portal', 32);
            $table->unsignedBigInteger('price_from_grosze');
            $table->unsignedBigInteger('price_to_grosze');
            $table->timestamp('observed_at');

            $table->timestamps();

            // Append-only, and only written when the price actually moved —
            // "seen again, same price" is not an event.
            $table->index(['listing_id', 'observed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_changes');
    }
};
