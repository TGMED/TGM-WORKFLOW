<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per person per year, written when their greeting goes out. The
 * unique key is what stops a second run on the same day sending it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('birthday_greetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->timestamp('sent_at');

            $table->unique(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('birthday_greetings');
    }
};
