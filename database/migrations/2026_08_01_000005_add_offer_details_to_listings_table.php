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
            // When the advert went up, as opposed to when we first saw it.
            // A flat sitting unsold for three months is a negotiating position.
            $table->timestamp('published_at')->nullable()->after('last_seen_at');

            // Private seller vs agency: no commission, and usually more room
            // to negotiate. Null where the portal does not say.
            $table->boolean('is_private_seller')->nullable()->after('published_at');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropColumn(['published_at', 'is_private_seller']);
        });
    }
};
