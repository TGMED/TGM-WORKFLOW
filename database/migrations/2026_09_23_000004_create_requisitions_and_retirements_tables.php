<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money asked for ahead of spending it, and the account of what was spent.
 *
 * A requisition names who is to be paid: the bank and account number as
 * given, and the account's name as the bank holds it, looked up through
 * Paystack rather than typed. A retirement closes one: what was spent, the
 * receipts, and what is owed back either way.
 *
 * Supporting documents are optional on both, and there can be several, so
 * they sit in one table of their own that points at either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->nullable()->unique();

            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            // Kept as it was when raised, so spend by department does not
            // wander when somebody moves.
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            $table->string('title');
            $table->text('purpose');
            $table->decimal('amount', 14, 2);

            $table->string('bank_code', 20);
            $table->string('bank_name');
            $table->string('account_number', 10);
            $table->string('account_name');

            $table->string('status')->default('pending');

            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();

            $table->foreignId('paid_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index('requester_id');
        });

        Schema::create('retirements', function (Blueprint $table) {
            $table->id();
            // One account per requisition. A retirement sent back is answered
            // by correcting it, not by filing a second one beside it.
            $table->foreignId('requisition_id')->unique()->constrained('requisitions')->cascadeOnDelete();

            $table->decimal('amount_spent', 14, 2);
            $table->text('notes')->nullable();

            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->morphs('attachable');
            $table->string('path');
            $table->string('name');
            $table->unsignedInteger('size')->nullable();
            $table->foreignId('uploaded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('retirements');
        Schema::dropIfExists('requisitions');
    }
};
