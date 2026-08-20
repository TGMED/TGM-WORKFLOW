<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who filed the request, when that was not the person it belongs to. An
 * approver can raise leave or lateness for a member of staff who cannot get
 * to the app themselves; the request is still the staff member's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->foreignId('raised_by_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });

        Schema::table('lateness_requests', function (Blueprint $table) {
            $table->foreignId('raised_by_id')->nullable()->after('user_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('raised_by_id');
        });

        Schema::table('lateness_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('raised_by_id');
        });
    }
};
