<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Days worked away from the office: from home, or out on company business.
 *
 * Deliberately not a leave type. Leave comes off an allowance and means the
 * person is not working; this means they are, somewhere else. Keeping the two
 * apart stops a week of fieldwork eating somebody's holiday, and stops the
 * roster reading a sales trip as time off.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('out_of_office_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('raised_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_lead_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('head_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('kind')->default('remote');

            $table->date('start_date');
            $table->date('end_date');
            // Workdays covered, counted the same way leave counts them, so the
            // two read alike on a roster even though only one is time off.
            $table->unsignedSmallInteger('days');
            $table->text('reason');

            // Where they will be and how to reach them. Asked for on an
            // assignment, where somebody may need to find them; left empty for
            // a day worked from home, where the usual numbers still apply.
            $table->string('destination')->nullable();
            $table->string('contact_number', 30)->nullable();

            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('approvals_required')->default(1);
            $table->timestamp('decided_at')->nullable();
            $table->timestamp('escalated_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('out_of_office_requests');
    }
};
