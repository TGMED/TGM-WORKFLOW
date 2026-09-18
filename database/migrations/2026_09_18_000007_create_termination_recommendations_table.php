<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A senior member of staff putting it to the people team that somebody who
 * answers to them should be let go.
 *
 * It is a recommendation and nothing more. Nobody's employment ends because a
 * row was written here: the people team reads it, answers it, and where they
 * accept it the exit is still recorded by hand on the staff page, with all the
 * clearing up that goes with it. Keeping the two apart is the point.
 *
 * Not an approval chain either. A chain would let a recommendation be granted
 * by collecting enough signatures, and this is one person's case put to the
 * people team for them to answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('termination_recommendations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('subject_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('raised_by_id')->constrained('users')->cascadeOnDelete();

            // The offence it rests on, where it rests on one. Nullable because
            // not every case is a named offence: persistent poor performance
            // is a reason to part company and is nowhere in the register.
            $table->foreignId('offence_id')->nullable()->constrained('offences')->nullOnDelete();
            // The incident report it grew out of, where there was one.
            $table->foreignId('report_id')->nullable()->constrained('reports')->nullOnDelete();

            $table->text('grounds');
            // How many times this has happened, which is what the offence's
            // ladder is read against.
            $table->unsignedTinyInteger('occurrence')->default(1);

            $table->string('status')->default('pending');
            $table->text('hr_note')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index('subject_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('termination_recommendations');
    }
};
