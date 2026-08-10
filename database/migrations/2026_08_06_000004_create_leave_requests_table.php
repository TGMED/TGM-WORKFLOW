<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->restrictOnDelete();

            $table->date('start_date');
            $table->date('end_date');
            // Workdays covered, counted against the type's yearly allowance.
            $table->unsignedSmallInteger('days');
            $table->text('reason')->nullable();

            $table->string('status')->default('pending');
            // Snapshot of the setting, so changing it later cannot re-open
            // requests that have already been decided.
            $table->unsignedTinyInteger('approvals_required')->default(1);
            $table->timestamp('decided_at')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'start_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_requests');
    }
};
