<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-person switches for what the app is allowed to send about, and down
 * which channel. A missing row means everything is on: people opt out here
 * rather than opt in, so a new topic reaches everyone by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('topic', 40);
            $table->boolean('email')->default(true);
            $table->boolean('push')->default(true);
            $table->timestamps();

            $table->unique(['user_id', 'topic']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_settings');
    }
};
