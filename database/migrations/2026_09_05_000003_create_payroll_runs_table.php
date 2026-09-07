<?php

use App\Enums\PayrollRunStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One month's payroll. A run is built as a draft, checked, and then finalised;
 * finalising is what puts the payslips in front of staff and freezes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            $table->string('status', 20)->default(PayrollRunStatus::Draft->value);

            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('finalised_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('finalised_at')->nullable();

            $table->timestamps();

            // A month is run once. Getting it wrong means deleting the draft
            // and rebuilding, not quietly running a second one alongside.
            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
