<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One person's pay for one month, as it was worked out at the time.
 *
 * The lines are frozen into the row rather than recomputed on the way out.
 * A payslip is a statement of what was actually paid: if the tax bands move
 * next April, last March's payslip must still say what it said in March.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();

            $table->foreignId('payroll_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('currency', 3)->default('NGN');

            // Kept alongside the run's own month so a payslip can be read on
            // its own without joining back for the period it covers.
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            // The named lines, each {label, amount} and, on deductions, the
            // basis it was worked out from, so the payslip can show its work.
            $table->json('earnings');
            $table->json('deductions');

            $table->decimal('gross_pay', 14, 2);
            $table->decimal('total_earnings', 14, 2);
            $table->decimal('total_deductions', 14, 2);
            $table->decimal('net_pay', 14, 2);

            // What the company paid on top, which is not the employee's money
            // but belongs on their statement.
            $table->decimal('employer_pension', 14, 2)->default(0);

            $table->timestamps();

            $table->unique(['payroll_run_id', 'user_id']);
            $table->index(['user_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
