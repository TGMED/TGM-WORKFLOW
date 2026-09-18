<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the pension schedule needs and the payslip did not keep.
 *
 * The employer's contribution was already frozen onto each payslip; the
 * employee's was only ever a line inside the deductions, which is fine to read
 * and wrong to total a remittance from. It is stored in its own right here.
 *
 * Remittance is recorded on the run, since that is what is paid over: one
 * transfer to the administrator covering everybody on the schedule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payslips', function (Blueprint $table) {
            $table->decimal('employee_pension', 12, 2)->default(0)->after('employer_pension');
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->timestamp('pension_remitted_at')->nullable()->after('finalised_at');
            // The bank reference or the administrator's receipt number, so the
            // entry means something when somebody queries it a year later.
            $table->string('pension_reference', 120)->nullable()->after('pension_remitted_at');
            $table->foreignId('pension_remitted_by_id')->nullable()->after('pension_reference')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pension_remitted_by_id');
            $table->dropColumn(['pension_remitted_at', 'pension_reference']);
        });

        Schema::table('payslips', function (Blueprint $table) {
            $table->dropColumn('employee_pension');
        });
    }
};
