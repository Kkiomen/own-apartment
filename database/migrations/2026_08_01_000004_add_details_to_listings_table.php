<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            // Enough to decide whether a flat is worth opening on the portal.
            $table->text('description')->nullable()->after('title');
            // Replaces the single image_url: the details view needs a gallery.
            $table->json('images')->nullable()->after('image_url');
        });

        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->text('image_url')->nullable();
            $table->dropColumn(['description', 'images']);
        });
    }
};
