<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A resignation now says whether notice was worked. Everything already on
 * file was recorded before the distinction existed, and the notice period is
 * not something that can be inferred from the row, so the safe reading is the
 * ordinary one: somebody who resigned gave notice. Anyone who walked out can
 * be corrected by hand on their staff page.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('exit_reason', 'resignation')
            ->update(['exit_reason' => 'resignation_with_notice']);
    }

    /**
     * Both new values collapse back to the one they came from.
     */
    public function down(): void
    {
        DB::table('users')
            ->whereIn('exit_reason', ['resignation_with_notice', 'resignation_without_notice'])
            ->update(['exit_reason' => 'resignation']);
    }
};
