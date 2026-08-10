<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Starting and ending the one break a staff member takes during a shift.
 *
 * Breaks are not geofenced: the person has already been checked onto the site
 * by their clock-in, and stepping out for lunch is the point.
 */
class BreakService
{
    /**
     * @return array{ok: bool, message: string, attendance: Attendance|null}
     */
    public function start(User $user): array
    {
        $location = $user->loadMissing('location')->location;

        if ($location === null) {
            return $this->fail('You have no work location yet, so there is no break to take.');
        }

        if (! $location->allowsBreaks()) {
            return $this->fail("Breaks are not tracked at {$location->name}.");
        }

        $attendance = $this->today($user, $location->timezone);

        if ($attendance === null || $attendance->clocked_in_at === null) {
            return $this->fail('Clock in before starting a break.');
        }

        if ($attendance->clocked_out_at !== null) {
            return $this->fail('You have already clocked out for the day.');
        }

        if ($attendance->isOnBreak()) {
            return $this->fail('You are already on a break.');
        }

        if ($attendance->hasTakenBreak()) {
            return $this->fail('You have already taken your break today.');
        }

        $attendance->forceFill(['break_started_at' => Carbon::now()])->save();

        return [
            'ok' => true,
            'message' => sprintf(
                'Break started. You are due back in %d minutes.',
                $location->break_minutes,
            ),
            'attendance' => $attendance,
        ];
    }

    /**
     * @return array{ok: bool, message: string, attendance: Attendance|null}
     */
    public function end(User $user): array
    {
        $location = $user->loadMissing('location')->location;

        if ($location === null) {
            return $this->fail('You have no work location yet, so there is no break to end.');
        }

        $attendance = $this->today($user, $location->timezone);

        if ($attendance === null || ! $attendance->isOnBreak()) {
            return $this->fail('You are not on a break.');
        }

        $now = Carbon::now();
        $minutes = max(0, (int) $attendance->break_started_at->diffInMinutes($now));

        $attendance->forceFill([
            'break_ended_at' => $now,
            'break_minutes' => $minutes,
        ])->save();

        $overrun = $attendance->breakOverrunMinutes($location->break_minutes);

        return [
            'ok' => true,
            'message' => $overrun > 0
                ? sprintf('Break ended after %d minutes, %d over the %d allowed.', $minutes, $overrun, $location->break_minutes)
                : sprintf('Break ended after %d minutes. Welcome back.', $minutes),
            'attendance' => $attendance,
        ];
    }

    /**
     * Today's record at the site's own local date.
     */
    protected function today(User $user, string $timezone): ?Attendance
    {
        return Attendance::query()
            ->where('user_id', $user->id)
            ->where('work_date', Carbon::now()->setTimezone($timezone)->toDateString())
            ->first();
    }

    /**
     * @return array{ok: bool, message: string, attendance: Attendance|null}
     */
    protected function fail(string $message): array
    {
        return ['ok' => false, 'message' => $message, 'attendance' => null];
    }
}
