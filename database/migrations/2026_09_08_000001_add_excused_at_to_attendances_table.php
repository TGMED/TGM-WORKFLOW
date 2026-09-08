<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When an approved lateness explanation excused this day.
 *
 * The day stays late: the status and the minutes are what actually happened
 * and are not rewritten. This says the company has accepted the reason, so
 * nothing that counts lateness should count
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 *
 * this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->timestamp('excused_at')->nullable()->after('late_minutes');
            $table->index(['status', 'excused_at']);
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table): void {
            $table->dropIndex(['status', 'excused_at']);
            $table->dropColumn('excused_at');
        });
    }
};
