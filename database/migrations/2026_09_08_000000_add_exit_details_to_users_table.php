<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why somebody left, and when they last worked.
 *
 * `is_active` already says whether they are still here; these say what
 * happened, which HR has to be able to answer long after the account has
 * gone quiet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('exit_reason')->nullable()->after('deactivated_at');
            $table->date('exit_date')->nullable()->after('exit_reason');
            $table->text('exit_note')->nullable()->after('exit_date');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['exit_reason', 'exit_date', 'exit_note']);
        });
    }
};
