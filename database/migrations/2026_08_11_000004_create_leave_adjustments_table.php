<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained()->cascadeOnDelete();
            // Allowances run to a calendar year, so an adjustment belongs to
            // one and does not follow the person into the next.
            $table->unsignedSmallInteger('year');
            // Signed: days granted on top of the type's allowance, or taken
            // off it. Never zero.
            $table->smallInteger('days');
            $table->string('reason');
            // Kept for the audit trail. Null once the admin who made the
            // adjustment has been deleted; the adjustment itself stands.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_adjustments');
    }
};
