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
            // Recomputed after every scan, so "best offers" is a plain indexed
            // sort rather than ranking thousands of rows in PHP per request.
            $table->integer('score')->default(0)->after('is_active');
            $table->json('labels')->nullable()->after('score');

            // city|area|rooms, without the price. The fingerprint deliberately
            // includes price so different prices stay separate rows; this key
            // is what lets us notice that two of those rows are the same flat
            // and say "taniej na innym portalu".
            $table->string('variant_key')->nullable()->after('fingerprint');
        });

        Schema::table('listings', function (Blueprint $table): void {
            $table->index(['is_active', 'score']);
            $table->index('variant_key');
        });
    }

    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table): void {
            $table->dropIndex(['is_active', 'score']);
            $table->dropIndex(['variant_key']);
            $table->dropColumn(['score', 'labels', 'variant_key']);
        });
    }
};
