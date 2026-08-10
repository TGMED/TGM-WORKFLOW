<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Both are nullable: requests raised before the chain existed keep
            // settling under the old any-approver rules.
            $table->foreignId('supervisor_id')->nullable()->after('leave_type_id')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('relief_officer_id')->nullable()->after('supervisor_id')
                ->constrained('users')->nullOnDelete();

            $table->index(['relief_officer_id', 'status']);
            $table->index(['supervisor_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropIndex(['relief_officer_id', 'status']);
            $table->dropIndex(['supervisor_id', 'status']);
            $table->dropConstrainedForeignId('relief_officer_id');
            $table->dropConstrainedForeignId('supervisor_id');
        });
    }
};
