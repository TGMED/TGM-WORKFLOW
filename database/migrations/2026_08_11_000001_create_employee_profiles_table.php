<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The HR record an employee keeps themselves. It sits beside users rather
 * than on it: users carries what the app needs to sign someone in and route
 * their requests, this carries what the people team needs on file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // Identity. users.name stays the display name and is recomposed
            // from these three whenever the profile is saved.
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('other_names')->nullable();
            $table->string('title', 20)->nullable();
            $table->string('gender', 20)->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('marital_status', 20)->nullable();
            $table->string('mothers_maiden_name')->nullable();
            $table->string('avatar_path')->nullable();

            // The badge number the clocking hardware knows this person by,
            // which is not the staff ID on users.
            $table->string('attendance_id', 40)->nullable();

            $table->string('spouse_name')->nullable();
            $table->string('spouse_phone', 30)->nullable();
            $table->unsignedSmallInteger('number_of_kids')->nullable();

            // Medical. Held so a site can act in an emergency.
            $table->string('blood_group', 5)->nullable();
            $table->string('genotype', 5)->nullable();
            $table->text('allergies')->nullable();
            $table->text('medical_history')->nullable();

            $table->string('religion', 40)->nullable();
            $table->string('national_id_number', 40)->nullable();

            // Origin.
            $table->string('country_of_origin', 2)->nullable();
            $table->string('state_of_origin')->nullable();
            $table->string('local_government')->nullable();

            // Contact. The primary phone and email stay on users, since the
            // rest of the app already reads them from there.
            $table->string('alternate_phone', 30)->nullable();
            $table->string('alternate_email')->nullable();

            // Bank. Nullable throughout: payroll chases these separately and
            // an incomplete set must not block someone from using the app.
            $table->string('bank_name')->nullable();
            $table->string('account_number', 20)->nullable();
            $table->string('account_name')->nullable();
            $table->string('bvn', 20)->nullable();
            $table->string('swift_code', 20)->nullable();
            $table->string('sort_code', 20)->nullable();
            $table->decimal('annual_rent', 14, 2)->nullable();

            $table->string('rsa_number', 30)->nullable();
            $table->string('pfa_name')->nullable();

            $table->string('tax_identification_number', 30)->nullable();
            $table->string('nhf_number', 30)->nullable();

            // Set the first time every required field is filled, so reporting
            // can tell a record that was finished from one that never was.
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
