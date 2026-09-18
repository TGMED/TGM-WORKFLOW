<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who each person reports to, as a line of its own.
 *
 * Heads of department and team leads answer a different question: who runs
 * this unit. That has stood in for a reporting line so far, and it does not
 * stretch far enough. Somebody can sit outside any team, report to a person in
 * another department, or report to a lead who is not their own. The organogram
 * needs the real answer, and so does anything that escalates.
 *
 * Backfilled from the line the company has been running on: team lead first,
 * head of department second, so nobody starts out orphaned. Nobody is ever
 * made their own manager.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('team_id')->constrained('users')->nullOnDelete();
        });

        DB::table('users')
            ->whereNotNull('team_id')
            ->update([
                'manager_id' => DB::raw(
                    '(select lead_user_id from teams where teams.id = users.team_id)',
                ),
            ]);

        DB::table('users')
            ->whereNull('manager_id')
            ->whereNotNull('department_id')
            ->update([
                'manager_id' => DB::raw(
                    '(select head_user_id from departments where departments.id = users.department_id)',
                ),
            ]);

        // A lead is not their own manager, and neither is a head.
        DB::table('users')->whereColumn('manager_id', 'id')->update(['manager_id' => null]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
        });
    }
};
