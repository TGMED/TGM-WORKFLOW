<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one member of staff has to say about another's work.
 *
 * The author is kept here and nowhere else. Public reviews are read by their
 * subject without a name on them, and private ones by HR alone, so this table
 * is deliberately left out of the audit trail: writing the author's id into it
 * would hand it to everyone who can read the trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('performance_reviews', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();

            // Worked out when the review is written, and kept as it was then:
            // somebody who later moves teams did not write it as a stranger.
            $table->string('standing');
            $table->string('visibility');

            $table->unsignedTinyInteger('rating');
            $table->text('body');

            $table->timestamps();
            $table->softDeletes();

            $table->index(['subject_user_id', 'visibility']);
            $table->index('reviewer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('performance_reviews');
    }
};
