<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who a request goes to before it reaches the wider approver pool.
 *
 * Stamped when the request is filed rather than read live off the requester,
 * so a reorganisation halfway through does not move a request out from under
 * the person already looking at it.
 *
 * Deliberately not backfilled: requests already in flight keep the chain they
 * were filed under. A null here means "this request predates the reporting
 * line", and the stage is skipped.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const TABLES = ['leave_requests', 'lateness_requests'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('team_lead_id')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
                $blueprint->foreignId('head_id')->nullable()->after('team_lead_id')->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('head_id');
                $blueprint->dropConstrainedForeignId('team_lead_id');
            });
        }
    }
};
