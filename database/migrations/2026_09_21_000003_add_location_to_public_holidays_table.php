<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A holiday is either company-wide (no site) or kept by one site, for a
     * state or local holiday the other offices work through. The date is no
     * longer unique on its own: two sites can each have a holiday on the same
     * day. The request keeps it to one holiday a day for any one site.
     */
    public function up(): void
    {
        Schema::table('public_holidays', function (Blueprint $table): void {
            $table->foreignId('location_id')->nullable()->after('date')->constrained()->cascadeOnDelete();
            $table->dropUnique(['date']);
            $table->index(['date', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::table('public_holidays', function (Blueprint $table): void {
            $table->dropIndex(['date', 'location_id']);
            $table->dropConstrainedForeignId('location_id');
            $table->unique('date');
        });
    }
};
