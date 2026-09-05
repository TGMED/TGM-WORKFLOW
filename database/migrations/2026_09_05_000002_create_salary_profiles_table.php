<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What somebody is paid.
 *
 * Deliberately not on `employee_profiles`: that table is the record an
 * employee edits themselves, and pay is the one thing on the HR file they
 * must not be able to write. This one is only ever written from the payroll
 * page, behind its own permission.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // The whole package for a year. Everything else — basic, housing,
            // pension, tax — is worked out from this and the settings, so
            // there is one number to keep right rather than eight.
            $table->decimal('annual_gross', 14, 2);

            // Staff who are out of the schemes: a contractor on the payroll,
            // somebody below the NHF threshold, an exemption on file.
            $table->boolean('pension_applies')->default(true);
            $table->boolean('nhf_applies')->default(true);

            $table->date('effective_from')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_profiles');
    }
};
