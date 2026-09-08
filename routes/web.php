<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\AttendanceReportController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\ClockAttemptController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\LeaveRestrictedPeriodController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\PayrollSettingsController;
use App\Http\Controllers\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Admin\RequestSettingsController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SalaryController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BreakController;
use App\Http\Controllers\ClockController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LatenessRequestController;
use App\Http\Controllers\LeaveEvidenceController;
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\OnBehalfRequestController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\PayslipController;
use App\Http\Controllers\Profile\AddressController;
use App\Http\Controllers\Profile\BankDetailsController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\ProfilePhotoController;
use App\Http\Controllers\Profile\RelationController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportEvidenceController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\PushTokenController;
use App\Http\Controllers\WhatsNewController;
use App\Http\Controllers\WhoIsAwayController;
use App\Http\Controllers\WorkLocationController;
use App\Imports\ImportRegistry;
use Illuminate\Support\Facades\Route;

// Import sheets are resolved by key rather than by id: an importer is code,
// not a row, and the registry is what knows the catalogue.
Route::bind('importer', function (string $key) {
    return app(ImportRegistry::class)->find($key)
        ?? abort(404, 'There is no import by that name.');
});

Route::redirect('/', '/dashboard')->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');

    Route::get('register', [RegisteredUserController::class, 'create'])->name('register');
    Route::post('register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('register.store');

    Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.email');

    Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:reset-password')
        ->name('password.store');
});

Route::middleware(['auth', 'active', 'profile-complete'])->group(function (): void {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('attendance', [AttendanceController::class, 'index'])
        ->middleware('clocks-in')
        ->name('attendance.index');

    // Who the company is missing today, open to everyone: cover is easier to
    // arrange when you can see who is out.
    Route::get('away', [WhoIsAwayController::class, 'index'])->name('away.index');

    // Staff who signed up before a site existed claim one here.
    Route::post('work-location', [WorkLocationController::class, 'store'])
        ->middleware('clocks-in')
        ->name('work-location.store');

    Route::post('clock/{type}', [ClockController::class, 'store'])
        ->whereIn('type', ['in', 'out'])
        ->middleware(['throttle:20,1', 'clocks-in'])
        ->name('clock.store');

    // Breaks are taken while already checked onto site, so they carry no
    // geofence of their own.
    Route::post('break/{action}', [BreakController::class, 'store'])
        ->whereIn('action', ['start', 'end'])
        ->middleware(['throttle:20,1', 'clocks-in'])
        ->name('break.store');

    // The document behind a request, which an approver has to be able to open
    // as well as the person it belongs to. It sits outside the group below
    // because an administrator does not work a shift but may still be asked
    // to rule on one.
    Route::get('leave/{leave}/evidence', [LeaveEvidenceController::class, 'show'])
        ->name('leave.evidence');

    // Somebody's own payslips. Outside the `clocks-in` group only because it
    // reads naturally beside the rest of the personal pages; an administrator
    // draws no salary here and simply sees an empty list.
    Route::get('payslips', [PayslipController::class, 'index'])->name('payslips.index');
    Route::get('payslips/{payslip}', [PayslipController::class, 'show'])->name('payslips.show');

    // Raising an incident is open to everyone who signs in, admins included:
    // there is no group of staff whose concerns the company does not want to
    // hear. It sits outside the `clocks-in` group below for that reason.
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::post('reports', [ReportController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('reports.store');

    // Guarded on the row rather than by a permission: the person who filed a
    // report can open their own attachment, and so can the reports desk.
    Route::get('reports/{report}/evidence', [ReportEvidenceController::class, 'show'])
        ->name('reports.evidence');

    // Requests are raised by the people who work a shift, so admins, who do
    // not, only see the settings and the approval inbox.
    Route::middleware('clocks-in')->group(function (): void {
        Route::get('leave', [LeaveRequestController::class, 'index'])->name('leave.index');
        Route::post('leave', [LeaveRequestController::class, 'store'])->name('leave.store');
        Route::put('leave/{leave}', [LeaveRequestController::class, 'update'])->name('leave.update');
        Route::delete('leave/{leave}', [LeaveRequestController::class, 'destroy'])->name('leave.destroy');

        Route::get('lateness', [LatenessRequestController::class, 'index'])->name('lateness.index');
        Route::post('lateness', [LatenessRequestController::class, 'store'])->name('lateness.store');
        Route::delete('lateness/{lateness}', [LatenessRequestController::class, 'destroy'])->name('lateness.destroy');
    });

    // Guarded by `approver` rather than the permission directly: a member of
    // staff named as relief officer reaches this page on the strength of the
    // cover they owe, holding no approval rights of their own. The middleware
    // still follows the roles page, since canApprove() reads the permission.
    Route::middleware('approver')->group(function (): void {
        Route::get('approvals', [ApprovalController::class, 'index'])->name('approvals.index');
        Route::post('approvals/{module}/{id}', [ApprovalController::class, 'store'])
            ->whereIn('module', ['leave', 'lateness'])
            ->whereNumber('id')
            ->name('approvals.store');

        // An approver filing a request for a member of staff who cannot file
        // it themselves. Guarded again in the form request, since a relief
        // officer reaches this group without approval rights.
        Route::post('approvals/on-behalf/leave', [OnBehalfRequestController::class, 'leave'])
            ->name('approvals.on-behalf.leave');
        Route::post('approvals/on-behalf/lateness', [OnBehalfRequestController::class, 'lateness'])
            ->name('approvals.on-behalf.lateness');
    });

    // The employee's own HR record. Reachable with the profile half-filled,
    // since this is where they go to finish it.
    Route::prefix('profile')->name('profile.')->group(function (): void {
        Route::get('/', [ProfileController::class, 'edit'])->name('edit');
        Route::put('/', [ProfileController::class, 'update'])->name('update');

        Route::put('bank', [BankDetailsController::class, 'update'])->name('bank.update');

        Route::post('photo', [ProfilePhotoController::class, 'store'])->name('photo.store');
        Route::delete('photo', [ProfilePhotoController::class, 'destroy'])->name('photo.destroy');

        Route::post('relations', [RelationController::class, 'store'])->name('relations.store');
        Route::put('relations/{relation}', [RelationController::class, 'update'])->name('relations.update');
        Route::delete('relations/{relation}', [RelationController::class, 'destroy'])->name('relations.destroy');

        Route::post('addresses', [AddressController::class, 'store'])->name('addresses.store');
        Route::put('addresses/{address}', [AddressController::class, 'update'])->name('addresses.update');
        Route::delete('addresses/{address}', [AddressController::class, 'destroy'])->name('addresses.destroy');
    });

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/notifications', [NotificationSettingsController::class, 'edit'])
        ->name('notifications.edit');
    Route::put('settings/notifications', [NotificationSettingsController::class, 'update'])
        ->name('notifications.update');

    // Written to by the page itself once the browser has handed it a Firebase
    // registration token, rather than by anything the person fills in.
    Route::post('push-tokens', [PushTokenController::class, 'store'])->name('push-tokens.store');
    Route::delete('push-tokens', [PushTokenController::class, 'destroy'])->name('push-tokens.destroy');

    Route::post('whats-new/seen', [WhatsNewController::class, 'store'])->name('whats-new.seen');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    // The admin area. Each page answers to its own permission rather than to
    // one blanket administrator check, so a role can be given the attendance
    // report without also being handed the staff list.
    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::middleware('permission:admin.dashboard')->group(function (): void {
            Route::get('/', AdminDashboardController::class)->name('dashboard');
        });

        Route::middleware('permission:staff.manage')->group(function (): void {
            Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
            Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
            Route::get('staff/{staff}', [StaffController::class, 'show'])->name('staff.show');
            Route::put('staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
            // Leaving is an event with a reason and a date, not a flag being
            // flipped, so it gets its own endpoint. Coming back is the flip.
            Route::post('staff/{staff}/exit', [StaffController::class, 'exit'])->name('staff.exit');
            Route::patch('staff/{staff}/reinstate', [StaffController::class, 'reinstate'])
                ->name('staff.reinstate');
        });

        Route::middleware('permission:locations.manage')->group(function (): void {
            Route::get('locations', [LocationController::class, 'index'])->name('locations.index');
            Route::post('locations', [LocationController::class, 'store'])->name('locations.store');
            Route::put('locations/{location}', [LocationController::class, 'update'])->name('locations.update');
            Route::patch('locations/{location}/toggle', [LocationController::class, 'toggle'])->name('locations.toggle');
            Route::patch('locations/{location}/reassign', [LocationController::class, 'reassign'])->name('locations.reassign');
        });

        // Attendance across the whole company for a chosen window, as
        // opposed to the personal month view staff see.
        Route::get('attendance', [AttendanceReportController::class, 'index'])
            ->middleware('permission:attendance.report')
            ->name('attendance.index');

        Route::get('clock-attempts', [ClockAttemptController::class, 'index'])
            ->middleware('permission:clock-attempts.view')
            ->name('clock-attempts.index');

        Route::middleware('permission:announcements.manage')->group(function (): void {
            Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
            Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
            Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
            Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');
        });

        // Leave types, their policy rules, closed periods and the approval
        // dials all belong to whoever runs the request process.
        Route::middleware('permission:request-settings.manage')->group(function (): void {
            Route::get('request-settings', [RequestSettingsController::class, 'index'])->name('request-settings.index');
            Route::put('request-settings/{module}', [RequestSettingsController::class, 'update'])
                ->whereIn('module', ['leave', 'lateness'])
                ->name('request-settings.update');

            Route::post('leave-types', [LeaveTypeController::class, 'store'])->name('leave-types.store');
            Route::put('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->name('leave-types.update');
            Route::patch('leave-types/{leaveType}/toggle', [LeaveTypeController::class, 'toggle'])->name('leave-types.toggle');

            Route::post('restricted-periods', [LeaveRestrictedPeriodController::class, 'store'])->name('restricted-periods.store');
            Route::put('restricted-periods/{restrictedPeriod}', [LeaveRestrictedPeriodController::class, 'update'])->name('restricted-periods.update');
            Route::delete('restricted-periods/{restrictedPeriod}', [LeaveRestrictedPeriodController::class, 'destroy'])->name('restricted-periods.destroy');
        });

        // Payroll: salaries, the rates pay is worked out under, and the
        // monthly runs. Behind its own permission — knowing what everyone in
        // the company earns is not something staff management should carry
        // along with it.
        Route::middleware('permission:payroll.manage')->group(function (): void {
            Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
            Route::post('payroll', [PayrollController::class, 'store'])->name('payroll.store');
            Route::get('payroll/{run}', [PayrollController::class, 'show'])->name('payroll.show');
            Route::post('payroll/{run}/rebuild', [PayrollController::class, 'rebuild'])->name('payroll.rebuild');
            Route::post('payroll/{run}/finalise', [PayrollController::class, 'finalise'])->name('payroll.finalise');
            Route::delete('payroll/{run}', [PayrollController::class, 'destroy'])->name('payroll.destroy');

            Route::put('payroll-settings', [PayrollSettingsController::class, 'update'])->name('payroll-settings.update');

            Route::post('salaries', [SalaryController::class, 'store'])->name('salaries.store');
            Route::delete('salaries/{salaryProfile}', [SalaryController::class, 'destroy'])->name('salaries.destroy');
        });

        // The reports desk. Behind its own permission because it is the one
        // page that identifies a reporter to somebody else; by default only
        // super admins hold it.
        Route::middleware('permission:reports.handle')->group(function (): void {
            Route::get('reports', [AdminReportController::class, 'index'])->name('reports.index');
            Route::put('reports/{report}', [AdminReportController::class, 'update'])->name('reports.update');
        });

        // Who may do what. Guarded by its own permission, which by default
        // only super admins hold.
        Route::middleware('permission:roles.manage')->group(function (): void {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
        });

        // Loading records in bulk. Two gates, both of which have to open: the
        // import permission below says somebody may use these pages at all,
        // and each sheet answers again to the permission that guards editing
        // those records by hand. So an import is never a way into a page
        // somebody cannot reach — it widens how much they can change at once,
        // never what.
        Route::middleware('permission:data.import')->group(function (): void {
            Route::get('imports', [ImportController::class, 'index'])->name('imports.index');

            Route::middleware('import-permitted')->group(function (): void {
                Route::get('imports/{importer}', [ImportController::class, 'show'])->name('imports.show');
                Route::get('imports/{importer}/template', [ImportController::class, 'template'])->name('imports.template');
                Route::get('imports/{importer}/reference', [ImportController::class, 'reference'])->name('imports.reference');

                // Checking a file and importing it are the same request, told
                // apart by one flag. A check writes nothing, so it is not
                // throttled any more tightly than the import it precedes.
                Route::post('imports/{importer}', [ImportController::class, 'store'])
                    ->middleware('throttle:20,1')
                    ->name('imports.store');
            });
        });

        // Read-only by design: an audit trail somebody can edit is not one.
        Route::get('audit', [AuditController::class, 'index'])
            ->middleware('permission:audit.view')
            ->name('audit.index');
    });
});
