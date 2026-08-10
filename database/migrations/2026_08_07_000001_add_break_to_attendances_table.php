<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            // One break per day, taken between clocking in and clocking out.
            $table->timestamp('break_started_at')->nullable()->after('clock_out_distance');
            $table->timestamp('break_ended_at')->nullable()->after('break_started_at');
            // Minutes the break actually ran for, settled when it ends. Kept
            // separate from worked_minutes, which has it deducted already.
            $table->unsignedInteger('break_minutes')->nullable()->after('break_ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['break_started_at', 'break_ended_at', 'break_minutes']);
        });
    }
};
