<?php

use App\Enums\EmploymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Probation, which the leave rules read: some types are closed to staff who
 * have not been confirmed yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('employment_status', 20)
                ->default(EmploymentStatus::Probation->value)
                ->after('hired_at');

            // Set when probation passes. Kept beside the status rather than
            // derived from it, since the date is what a confirmation letter
            // has to quote.
            $table->date('confirmed_at')->nullable()->after('employment_status');
        });

        // Everyone already on the books predates the column and has been
        // working here on the old rules. Dropping them into probation would
        // shut them out of leave they can take today, so they come across
        // confirmed and HR walks anyone back who is not.
        DB::table('users')->update([
            'employment_status' => EmploymentStatus::Confirmed->value,
            'confirmed_at' => DB::raw('hired_at'),
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['employment_status', 'confirmed_at']);
        });
    }
};
