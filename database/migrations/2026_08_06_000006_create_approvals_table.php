<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approvals', function (Blueprint $table) {
            $table->id();
            // Leave and lateness requests share this ledger.
            $table->morphs('approvable');
            $table->foreignId('approver_id')->constrained('users')->cascadeOnDelete();

            // Position in the run of decisions on this request, 1-based.
            $table->unsignedTinyInteger('step')->default(1);
            $table->string('decision');
            $table->text('comment')->nullable();
            $table->timestamp('decided_at');

            $table->timestamps();

            // Nobody decides on the same request twice.
            $table->unique(['approvable_type', 'approvable_id', 'approver_id'], 'approvals_unique_decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approvals');
    }
};
