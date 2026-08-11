<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listing_sources', function (Blueprint $table): void {
            // When we last opened the offer page itself. Distinct from
            // last_seen_at, which only says the advert turned up in a search.
            // Null means never checked, and those go first in the queue.
            $table->timestamp('last_verified_at')->nullable()->after('last_seen_at');

            // The verification queue's only query: active adverts, oldest check
            // first. Without it every run table-scans listing_sources.
            $table->index(['is_active', 'last_verified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('listing_sources', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'last_verified_at']);
            $table->dropColumn('last_verified_at');
        });
    }
};
