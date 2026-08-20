<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notices the people team puts in front of everyone on the dashboard. A
 * window rather than a feed: each one carries when it starts showing and
 * when it stops, so nobody has to remember to take it down.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();

            // Whoever posted it. Nullable so retiring an administrator does
            // not take their notices down with them.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('title');
            $table->text('body');

            // Pinned notices sort above the rest, whatever their date.
            $table->boolean('is_pinned')->default(false);

            // Null means a draft nobody sees yet.
            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Stamped when the notice was mailed and pushed to the company.
            // Publishing is what sends it, and this is what stops a notice
            // pulled back and published again going out a second time.
            $table->timestamp('notified_at')->nullable();

            $table->timestamps();

            $table->index(['published_at', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};
