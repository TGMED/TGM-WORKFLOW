<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stretches of the calendar the business closes to leave — a peak season, a
 * year-end count, a go-live week. Staff cannot book time off over one unless
 * the period lets them through, either on the marital status they carry or on
 * the type of leave they are asking for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_restricted_periods', function (Blueprint $table) {
            $table->id();

            // Whoever set it. Nullable so retiring an administrator does not
            // take their restrictions down with them.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');

            // Shown to staff when a request is turned away, so the block
            // explains itself rather than reading as a bug.
            $table->string('reason')->nullable();

            // Stored as bare dates: a restriction covers whole days, and the
            // comparisons line up on every driver this way.
            $table->date('start_date');
            $table->date('end_date');

            // Marital statuses that may book anyway, from the pick-list in
            // config/profile.php. An empty list closes the period to everyone.
            $table->json('exempt_marital_statuses')->nullable();

            $table->timestamps();

            $table->index(['start_date', 'end_date']);
        });

        // Types of leave the restriction does not apply to — sick and
        // compassionate being the usual ones, since they cannot be planned
        // around a closed window.
        Schema::create('leave_restricted_period_leave_type', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('leave_restricted_period_id');
            $table->unsignedBigInteger('leave_type_id');

            // Named by hand: the keys MySQL would derive from this table run
            // past its 64-character limit on identifiers.
            $table->foreign('leave_restricted_period_id', 'restricted_period_type_period_fk')
                ->references('id')->on('leave_restricted_periods')->cascadeOnDelete();
            $table->foreign('leave_type_id', 'restricted_period_type_type_fk')
                ->references('id')->on('leave_types')->cascadeOnDelete();

            $table->unique(['leave_restricted_period_id', 'leave_type_id'], 'restricted_period_leave_type_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_restricted_period_leave_type');
        Schema::dropIfExists('leave_restricted_periods');
    }
};
