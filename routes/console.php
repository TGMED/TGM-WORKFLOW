<?php

use App\Console\Commands\SendDueAnnouncements;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A notice published on the spot goes out there and then; this is only for
// the ones dated to go up later.
Schedule::command(SendDueAnnouncements::class)
    ->everyTenMinutes()
    ->withoutOverlapping();
