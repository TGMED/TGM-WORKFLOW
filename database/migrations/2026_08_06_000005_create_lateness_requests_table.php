<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lateness_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The late day being explained. Kept nullable so the record
            // survives an attendance row being removed.
            $table->foreignId('attendance_id')->nullable()->constrained()->nullOnDelete();

            $table->date('work_date');
            $table->unsignedInteger('minutes_late')->default(0);
            $table->text('reason');

            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('approvals_required')->default(1);
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            // One explanation per person per day.
            $table->unique(['user_id', 'work_date']);
            $table->index(['status', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lateness_requests');
    }
};
