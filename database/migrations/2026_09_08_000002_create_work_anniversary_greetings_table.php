<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per person per year, written when their anniversary note goes out.
 * The unique key is what stops a second run on the same day sending it again,
 * exactly as the birthday log does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_anniversary_greetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedSmallInteger('years_of_service');
            $table->timestamp('sent_at');
            $table->softDeletes();

            $table->unique(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_anniversary_greetings');
    }
};
