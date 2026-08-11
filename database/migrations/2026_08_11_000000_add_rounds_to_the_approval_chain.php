<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // Bumped each time a returned request is sent round the chain
            // again. Everything already raised is on its first round.
            $table->unsignedTinyInteger('round')->default(1)->after('approvals_required');
        });

        Schema::table('approvals', function (Blueprint $table) {
            $table->unsignedTinyInteger('round')->default(1)->after('step');

            // Nobody decides twice in the same round, but a resubmission is a
            // fresh round and asks the same people again.
            $table->dropUnique('approvals_unique_decision');
            $table->unique(
                ['approvable_type', 'approvable_id', 'approver_id', 'round'],
                'approvals_unique_decision',
            );
        });
    }

    public function down(): void
    {
        Schema::table('approvals', function (Blueprint $table) {
            $table->dropUnique('approvals_unique_decision');
            $table->dropColumn('round');
            $table->unique(
                ['approvable_type', 'approvable_id', 'approver_id'],
                'approvals_unique_decision',
            );
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('round');
        });
    }
};
