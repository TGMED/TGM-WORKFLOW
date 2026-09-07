<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A deleted row stops being read rather than stops existing. Every table
 * behind a model gets the column, so nothing an administrator removes by
 * hand is gone for good and the audit trail has a row left to point at.
 *
 * Note the tables carrying unique indexes: a soft-deleted row still holds
 * its slot in one, so recreating a record that matches a deleted one fails
 * until the old row is force-deleted or restored.
 */
return new class extends Migration
{
    /**
     * Every table with a model in front of it.
     *
     * @var list<string>
     */
    private const TABLES = [
        'announcements',
        'approval_settings',
        'approvals',
        'attendances',
        'audits',
        'birthday_greetings',
        'clock_attempts',
        'employee_addresses',
        'employee_profiles',
        'employee_relations',
        'lateness_requests',
        'leave_requests',
        'leave_restricted_periods',
        'leave_types',
        'locations',
        'notification_settings',
        'payroll_runs',
        'payroll_settings',
        'payslips',
        'push_tokens',
        'reports',
        'role_permissions',
        'roles',
        'salary_profiles',
        'users',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->softDeletes();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropSoftDeletes();
            });
        }
    }
};
