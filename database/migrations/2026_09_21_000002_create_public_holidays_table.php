<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Days the whole company is off. One row a day, company-wide: a holiday
     * that runs two days (Easter Monday after Good Friday) is two rows, which
     * is how they are declared anyway.
     */
    public function up(): void
    {
        Schema::create('public_holidays', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->date('date')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('public_holidays');
    }
};
