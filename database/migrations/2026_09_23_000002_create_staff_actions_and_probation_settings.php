<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Probation moves from a config value to something HR sets, with a length of
 * its own for anybody whose contract says otherwise; and the letters HR sends
 * about somebody's standing (a query, a warning, a confirmation) are kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employment_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('probation_months')->default(6);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::table('users', function (Blueprint $table) {
            // Null follows the company's length. Set where a contract says
            // otherwise, and kept even after confirmation, as the record of
            // what they were held to.
            $table->unsignedTinyInteger('probation_months')->nullable()->after('confirmed_at');
        });

        Schema::create('staff_actions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('issued_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind');
            $table->string('title');
            $table->text('body');

            // The register entry a query or warning rests on, where there is one.
            $table->foreignId('offence_id')->nullable()->constrained('offences')->nullOnDelete();

            // A query asks for an answer, by a date.
            $table->date('response_due_on')->nullable();
            $table->text('response')->nullable();
            $table->timestamp('responded_at')->nullable();

            // A warning or confirmation is only read, and says so once it is.
            $table->timestamp('acknowledged_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_user_id', 'kind']);
            $table->index(['kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_actions');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('probation_months');
        });

        Schema::dropIfExists('employment_settings');
    }
};
