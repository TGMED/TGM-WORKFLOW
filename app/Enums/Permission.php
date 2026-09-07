<?php

namespace App\Enums;

/**
 * What a role may do. The catalogue is code, because each entry guards a
 * route or a query that only exists in code; which roles hold which entries
 * is data, edited from the roles page.
 *
 * Super admins hold every permission implicitly, so the system can never be
 * locked out of itself by an unlucky edit.
 */
enum Permission: string
{
    case ViewAdminDashboard = 'admin.dashboard';
    case ManageStaff = 'staff.manage';
    case ManageLocations = 'locations.manage';
    case ViewAttendanceReport = 'attendance.report';
    case ViewClockAttempts = 'clock-attempts.view';
    case ManageAnnouncements = 'announcements.manage';
    case ManageRequestSettings = 'request-settings.manage';
    case ApproveRequests = 'requests.approve';
    case ManagePayroll = 'payroll.manage';
    case HandleReports = 'reports.handle';
    case ImportData = 'data.import';
    case ManageRoles = 'roles.manage';
    case ViewAuditTrail = 'audit.view';

    public function label(): string
    {
        return match ($this) {
            self::ViewAdminDashboard => 'See the admin dashboard',
            self::ManageStaff => 'Manage staff',
            self::ManageLocations => 'Manage sites',
            self::ViewAttendanceReport => 'See the attendance report',
            self::ViewClockAttempts => 'See rejected clock attempts',
            self::ManageAnnouncements => 'Manage announcements',
            self::ManageRequestSettings => 'Manage request settings',
            self::ApproveRequests => 'Decide on requests',
            self::ManagePayroll => 'Run payroll and set salaries',
            self::HandleReports => 'Read and handle incident reports',
            self::ImportData => 'Import data from a file',
            self::ManageRoles => 'Manage roles and permissions',
            self::ViewAuditTrail => 'See the audit trail',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ViewAdminDashboard => 'The company-wide console: headcount, attendance and requests at a glance.',
            self::ManageStaff => 'Add people, edit their record, change their role, deactivate them.',
            self::ManageLocations => 'Add and configure sites, their geofence and their working day.',
            self::ViewAttendanceReport => 'Attendance across the whole company for any window.',
            self::ViewClockAttempts => 'Clock-ins the geofence turned away, and why.',
            self::ManageAnnouncements => 'Write and publish company notices.',
            self::ManageRequestSettings => 'Leave types, their policy rules, closed periods and approval counts.',
            self::ApproveRequests => 'The approvals inbox, and filing a request for somebody else.',
            self::ManagePayroll => 'Salaries, the tax and pension rates, and building and signing off each month\'s payslips.',
            self::HandleReports => 'The reports desk: read what staff have raised, including who raised it, and close a case. Give this to as few people as the company can manage.',
            self::ImportData => 'Load records in bulk from a spreadsheet. Each sheet still answers to the permission that guards editing those records by hand, so this widens how much somebody can change at once, never what.',
            self::ManageRoles => 'Create roles and choose what each one may do.',
            self::ViewAuditTrail => 'Who changed what, and when. Read-only.',
        };
    }

    /**
     * How the roles page groups the checkboxes.
     */
    public function group(): string
    {
        return match ($this) {
            self::ViewAdminDashboard, self::ViewAttendanceReport, self::ViewClockAttempts, self::ViewAuditTrail => 'Visibility',
            self::ManageStaff, self::ManageLocations, self::ManageAnnouncements => 'People and sites',
            self::ManagePayroll, self::HandleReports => 'Confidential',
            self::ManageRequestSettings, self::ApproveRequests => 'Requests',
            self::ImportData, self::ManageRoles => 'System',
        };
    }

    /**
     * Guards a page that only makes sense to somebody who does not work a
     * shift, and which therefore belongs behind the admin area.
     */
    public function isAdministrative(): bool
    {
        return $this !== self::ApproveRequests;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The catalogue as the roles page renders it: grouped, in declaration
     * order, each entry carrying what it lets somebody do.
     *
     * @return array<int, array{group: string, permissions: array<int, array{value: string, label: string, description: string}>}>
     */
    public static function catalogue(): array
    {
        $groups = [];

        foreach (self::cases() as $permission) {
            $groups[$permission->group()][] = [
                'value' => $permission->value,
                'label' => $permission->label(),
                'description' => $permission->description(),
            ];
        }

        return array_map(
            fn (string $group, array $permissions): array => [
                'group' => $group,
                'permissions' => $permissions,
            ],
            array_keys($groups),
            $groups,
        );
    }
}
