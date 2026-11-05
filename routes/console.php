<?php

use App\Console\Commands\EscalateStaleApprovals;
use App\Console\Commands\SendApprovalReminders;
use App\Console\Commands\SendBirthdayGreetings;
use App\Console\Commands\SendDueAnnouncements;
use App\Console\Commands\SendWorkAnniversaryGreetings;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Early enough to land before the working day, late enough not to wake
// anyone. A missed run can be caught up with `--date`.
Schedule::command(SendBirthdayGreetings::class)
    ->dailyAt('07:00')
    ->withoutOverlapping();

// A notice published on the spot goes out there and then; this is only for
// the ones dated to go up later.
Schedule::command(SendDueAnnouncements::class)
    ->everyTenMinutes()
    ->withoutOverlapping();

// Alongside the birthday run, and for the same reasons. Kept a few minutes
// apart so a person marking both on one day gets two separate notes rather
// than two sends racing each other.
Schedule::command(SendWorkAnniversaryGreetings::class)
    ->dailyAt('07:05')
    ->withoutOverlapping();

// Hourly is close enough for a deadline set in hours, and keeps the note
// arriving at a sensible hour rather than in the middle of the night.
Schedule::command(EscalateStaleApprovals::class)
    ->hourly()
    ->withoutOverlapping();

// The approvers' morning reminder. Weekdays only: nobody decides leave on a
// Sunday, and Monday's email covers the weekend anyway.
Schedule::command(SendApprovalReminders::class)
    ->weekdays()
    ->dailyAt((string) config('hr.approval_reminders.at', '08:00'))
    ->timezone((string) config('hr.approval_reminders.timezone', 'Africa/Lagos'))
    ->withoutOverlapping();

// Horizon's metrics graphs are built from snapshots rather than kept live, so
// without this run the dashboard's throughput and wait times stay empty.
Schedule::command('horizon:snapshot')->everyFiveMinutes();
