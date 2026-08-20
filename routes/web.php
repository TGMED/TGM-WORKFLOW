<?php

use App\Http\Controllers\Admin\AnnouncementController;
use App\Http\Controllers\Admin\ClockAttemptController;
use App\Http\Controllers\Admin\LeaveTypeController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\RequestSettingsController;
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
use App\Http\Controllers\LeaveRequestController;
use App\Http\Controllers\OnBehalfRequestController;
use App\Http\Controllers\PasswordController;
use App\Http\Controllers\Profile\AddressController;
use App\Http\Controllers\Profile\BankDetailsController;
use App\Http\Controllers\Profile\ProfileController;
use App\Http\Controllers\Profile\ProfilePhotoController;
use App\Http\Controllers\Profile\RelationController;
use App\Http\Controllers\Settings\NotificationSettingsController;
use App\Http\Controllers\Settings\PushTokenController;
use App\Http\Controllers\WhatsNewController;
use App\Http\Controllers\WorkLocationController;
use Illuminate\Support\Facades\Route;

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

    Route::middleware('super-admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::get('staff', [StaffController::class, 'index'])->name('staff.index');
        Route::post('staff', [StaffController::class, 'store'])->name('staff.store');
        Route::get('staff/{staff}', [StaffController::class, 'show'])->name('staff.show');
        Route::put('staff/{staff}', [StaffController::class, 'update'])->name('staff.update');
        Route::patch('staff/{staff}/toggle', [StaffController::class, 'toggle'])->name('staff.toggle');

        Route::get('locations', [LocationController::class, 'index'])->name('locations.index');
        Route::post('locations', [LocationController::class, 'store'])->name('locations.store');
        Route::put('locations/{location}', [LocationController::class, 'update'])->name('locations.update');
        Route::patch('locations/{location}/toggle', [LocationController::class, 'toggle'])->name('locations.toggle');
        Route::patch('locations/{location}/reassign', [LocationController::class, 'reassign'])->name('locations.reassign');

        Route::get('clock-attempts', [ClockAttemptController::class, 'index'])->name('clock-attempts.index');

        Route::get('request-settings', [RequestSettingsController::class, 'index'])->name('request-settings.index');
        Route::put('request-settings/{module}', [RequestSettingsController::class, 'update'])
            ->whereIn('module', ['leave', 'lateness'])
            ->name('request-settings.update');

        Route::get('announcements', [AnnouncementController::class, 'index'])->name('announcements.index');
        Route::post('announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
        Route::put('announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
        Route::delete('announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');

        Route::post('leave-types', [LeaveTypeController::class, 'store'])->name('leave-types.store');
        Route::put('leave-types/{leaveType}', [LeaveTypeController::class, 'update'])->name('leave-types.update');
        Route::patch('leave-types/{leaveType}/toggle', [LeaveTypeController::class, 'toggle'])->name('leave-types.toggle');
    });
});
