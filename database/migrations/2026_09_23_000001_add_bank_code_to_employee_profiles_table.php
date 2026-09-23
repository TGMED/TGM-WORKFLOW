<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bank's code as Paystack knows it, which is what an account is looked up
 * against. The name stays beside it for payroll and for reading, but the code
 * is what says which bank it is.
 *
 * Left empty on existing rows: the old list held names only, and the form
 * matches those to a code the next time somebody opens it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->string('bank_code', 20)->nullable()->after('bank_name');
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn('bank_code');
        });
    }
};
