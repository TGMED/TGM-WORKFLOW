<?php

use App\Http\Controllers\Admin\ClockAttemptController;
use App\Http\Controllers\Admin\LocationController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\BreakController;
use App\Http\Controllers\ClockController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PasswordController;
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
    Route::post('reset-password', [NewPasswordController::class, 'store'])->name('password.store');
});

Route::middleware(['auth', 'active'])->group(function (): void {
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

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

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
    });
});
