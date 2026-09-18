<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A request nobody has ruled on is not refused, it is simply forgotten, and
 * the person waiting on it has no way of telling the difference. After a set
 * stretch the people team and whoever manages the approver sitting on it are
 * told, so somebody can go and ask.
 *
 * The stretch is per module and may be null, which switches escalation off for
 * that module entirely. `escalated_at` is stamped on the request so the note
 * goes out once rather than every hour until a decision lands.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const REQUEST_TABLES = ['leave_requests', 'lateness_requests'];

    public function up(): void
    {
        Schema::table('approval_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('escalation_hours')->nullable()->after('approvers_required');
        });

        foreach (self::REQUEST_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->timestamp('escalated_at')->nullable()->after('decided_at');
            });
        }
    }

    public function down(): void
    {
        Schema::table('approval_settings', function (Blueprint $table) {
            $table->dropColumn('escalation_hours');
        });

        foreach (self::REQUEST_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('escalated_at');
            });
        }
    }
};
