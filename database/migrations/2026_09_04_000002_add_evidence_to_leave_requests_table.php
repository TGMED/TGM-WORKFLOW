<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sick paper, death certificate or letter behind a request the policy
 * will not grant on somebody's word alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            // On the private disk, not the public one: this is medical and
            // bereavement paperwork, and a guessable URL is no protection.
            $table->string('evidence_path')->nullable()->after('reason');
            $table->string('evidence_name')->nullable()->after('evidence_path');
        });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn(['evidence_path', 'evidence_name']);
        });
    }
};
