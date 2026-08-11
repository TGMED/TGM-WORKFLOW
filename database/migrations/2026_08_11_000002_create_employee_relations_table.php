<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Next of kin, dependants and family members. The three lists ask for the
 * same handful of details, so they share a table and are told apart by kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);

            $table->string('name');
            $table->string('relationship', 40);
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->string('gender', 20)->nullable();
            $table->string('occupation')->nullable();
            $table->text('address')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_relations');
    }
};
