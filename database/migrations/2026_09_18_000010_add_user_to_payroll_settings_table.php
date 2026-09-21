<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rates for one person rather than for the company.
 *
 * A null `user_id` is the company row, which is what every run used before
 * this and what everybody still falls back to. A set one is that person's own
 * rates, and it replaces the company row outright rather than being merged
 * with it: somebody on a personal set is on it whole, so a rate on their slip
 * can always be pointed at one row rather than reconstructed from two.
 *
 * The consequence is deliberate and worth saying out loud: a personal row does
 * not follow the company rates when those change. Whoever puts somebody on one
 * has taken on keeping it right.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->foreignId('user_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();
        });

        // One set of rates per person, and one company row. Sqlite and MySQL
        // both treat nulls as distinct in a unique index, so this constrains
        // the personal rows without standing in the way of the company one.
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_settings', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
