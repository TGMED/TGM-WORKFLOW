<?php

namespace App\Services;

use App\Enums\AttemptResult;
use App\Enums\AttendanceStatus;
use App\Enums\ClockType;
use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\Location;
use App\Models\User;
use App\Support\Geo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ClockService
{
    /**
     * Record a clock-in against the staff member's own site. Every attempt is
     * persisted, successful or not.
     */
    public function clockIn(User $user, ClockPunch $punch): ClockResult
    {
        $location = $user->location;

        if ($location === null) {
            return $this->reject(
                $user,
                ClockType::In,
                AttemptResult::NoLocationAssigned,
                'You have no work location yet. Ask your administrator to assign one.',
                $punch,
                null,
            );
        }

        $now = Carbon::now();
        $localNow = $now->copy()->setTimezone($location->timezone);
        $workDate = $localNow->toDateString();

        $existing = $this->attendanceFor($user, $workDate);

        if ($existing?->clocked_in_at !== null) {
            return $this->reject(
                $user,
                ClockType::In,
                AttemptResult::Duplicate,
                'You already clocked in at '.$existing->clocked_in_at->copy()->setTimezone($location->timezone)->format('g:i A').'.',
                $punch,
                $location,
                $existing,
            );
        }

        $guard = $this->guardLocation($user, ClockType::In, $punch, $location);

        if ($guard instanceof ClockResult) {
            return $guard;
        }

        $distance = $guard;

        [$status, $lateMinutes] = $this->evaluateLateness($location, $localNow);

        $attendance = DB::transaction(fn (): Attendance => Attendance::query()->updateOrCreate(
            ['user_id' => $user->id, 'work_date' => $workDate],
            [
                'location_id' => $location->id,
                'clocked_in_at' => $now,
                'clock_in_latitude' => $punch->latitude,
                'clock_in_longitude' => $punch->longitude,
                'clock_in_accuracy' => $punch->accuracy,
                'clock_in_distance' => $distance,
                'status' => $status,
                'late_minutes' => $lateMinutes,
            ],
        ));

        $message = $status === AttendanceStatus::Late
            ? sprintf('Clocked in at %s at %s, %s late.', $localNow->format('g:i A'), $location->name, $this->humanizeMinutes($lateMinutes))
            : sprintf('Clocked in at %s at %s. You are on time.', $localNow->format('g:i A'), $location->name);

        return new ClockResult(
            AttemptResult::Success,
            $message,
            $this->log($user, ClockType::In, AttemptResult::Success, $message, $punch, $distance, $location, $attendance),
            $attendance,
        );
    }

    /**
     * Record a clock-out against today's open attendance record.
     */
    public function clockOut(User $user, ClockPunch $punch): ClockResult
    {
        $location = $user->location;

        if ($location === null) {
            return $this->reject(
                $user,
                ClockType::Out,
                AttemptResult::NoLocationAssigned,
                'You have no work location yet. Ask your administrator to assign one.',
                $punch,
                null,
            );
        }

        $now = Carbon::now();
        $localNow = $now->copy()->setTimezone($location->timezone);
        $workDate = $localNow->toDateString();

        $attendance = $this->attendanceFor($user, $workDate);

        if ($attendance === null || $attendance->clocked_in_at === null) {
            return $this->reject(
                $user,
                ClockType::Out,
                AttemptResult::NotClockedIn,
                'You have not clocked in today.',
                $punch,
                $location,
                $attendance,
            );
        }

        if ($attendance->clocked_out_at !== null) {
            return $this->reject(
                $user,
                ClockType::Out,
                AttemptResult::Duplicate,
                'You already clocked out at '.$attendance->clocked_out_at->copy()->setTimezone($location->timezone)->format('g:i A').'.',
                $punch,
                $location,
                $attendance,
            );
        }

        $guard = $this->guardLocation($user, ClockType::Out, $punch, $location, $attendance);

        if ($guard instanceof ClockResult) {
            return $guard;
        }

        $distance = $guard;

        // Clocking out closes a break left running, so the day cannot be paid
        // as if someone never came back from lunch.
        if ($attendance->isOnBreak()) {
            $attendance->forceFill([
                'break_ended_at' => $now,
                'break_minutes' => max(0, (int) $attendance->break_started_at->diffInMinutes($now)),
            ])->save();
        }

        $onSite = max(0, (int) $attendance->clocked_in_at->diffInMinutes($now));
        $breakMinutes = (int) ($attendance->break_minutes ?? 0);

        $attendance->forceFill([
            'clocked_out_at' => $now,
            'clock_out_latitude' => $punch->latitude,
            'clock_out_longitude' => $punch->longitude,
            'clock_out_accuracy' => $punch->accuracy,
            'clock_out_distance' => $distance,
            // Break time is not time on the clock.
            'worked_minutes' => max(0, $onSite - $breakMinutes),
        ])->save();

        $message = $breakMinutes > 0
            ? sprintf(
                'Clocked out at %s, %s on the clock after a %s break.',
                $localNow->format('g:i A'),
                $this->humanizeMinutes((int) $attendance->worked_minutes),
                $this->humanizeMinutes($breakMinutes),
            )
            : sprintf(
                'Clocked out at %s, %s on the clock.',
                $localNow->format('g:i A'),
                $this->humanizeMinutes((int) $attendance->worked_minutes),
            );

        return new ClockResult(
            AttemptResult::Success,
            $message,
            $this->log($user, ClockType::Out, AttemptResult::Success, $message, $punch, $distance, $location, $attendance),
            $attendance->fresh(),
        );
    }

    /**
     * Validate the punch against the site's geofence.
     *
     * Returns the distance in metres when the punch is allowed, or a rejected
     * ClockResult when it is not.
     */
    protected function guardLocation(
        User $user,
        ClockType $type,
        ClockPunch $punch,
        Location $location,
        ?Attendance $attendance = null,
    ): int|ClockResult {
        if (! $location->hasCoordinates()) {
            return $this->reject(
                $user,
                $type,
                AttemptResult::NoLocationConfigured,
                sprintf('%s has no coordinates set yet. Contact your administrator.', $location->name),
                $punch,
                $location,
                $attendance,
            );
        }

        if (! $punch->hasCoordinates()) {
            return $this->reject(
                $user,
                $type,
                AttemptResult::NoLocation,
                'We could not read your location. Enable location access and try again.',
                $punch,
                $location,
                $attendance,
            );
        }

        if ($punch->accuracy !== null && $punch->accuracy > $location->max_accuracy_meters) {
            return $this->reject(
                $user,
                $type,
                AttemptResult::LowAccuracy,
                sprintf(
                    'Your GPS signal is accurate to only %dm. Move to an open area and try again.',
                    $punch->accuracy,
                ),
                $punch,
                $location,
                $attendance,
            );
        }

        $distance = Geo::distanceInMeters(
            $punch->latitude,
            $punch->longitude,
            $location->latitude,
            $location->longitude,
        );

        if ($distance > $location->radius_meters) {
            return $this->reject(
                $user,
                $type,
                AttemptResult::OutOfRange,
                sprintf(
                    'You are %s from %s. You must be within %dm to clock %s.',
                    $this->humanizeDistance($distance),
                    $location->name,
                    $location->radius_meters,
                    $type->value,
                ),
                $punch,
                $location,
                $attendance,
                $distance,
            );
        }

        return $distance;
    }

    /**
     * @return array{0: AttendanceStatus, 1: int}
     */
    protected function evaluateLateness(Location $location, Carbon $localNow): array
    {
        $start = $location->startOfWorkFor($localNow);
        $cutoff = $location->latenessCutoffFor($localNow);

        if ($localNow->lessThanOrEqualTo($cutoff)) {
            return [AttendanceStatus::OnTime, 0];
        }

        return [AttendanceStatus::Late, (int) $start->diffInMinutes($localNow)];
    }

    protected function attendanceFor(User $user, string $workDate): ?Attendance
    {
        return Attendance::query()
            ->where('user_id', $user->id)
            ->where('work_date', $workDate)
            ->first();
    }

    protected function reject(
        User $user,
        ClockType $type,
        AttemptResult $result,
        string $message,
        ClockPunch $punch,
        ?Location $location,
        ?Attendance $attendance = null,
        ?int $distance = null,
    ): ClockResult {
        if ($distance === null && $punch->hasCoordinates() && $location?->hasCoordinates()) {
            $distance = Geo::distanceInMeters(
                $punch->latitude,
                $punch->longitude,
                $location->latitude,
                $location->longitude,
            );
        }

        return new ClockResult(
            $result,
            $message,
            $this->log($user, $type, $result, $message, $punch, $distance, $location, $attendance),
            $attendance,
        );
    }

    protected function log(
        User $user,
        ClockType $type,
        AttemptResult $result,
        string $message,
        ClockPunch $punch,
        ?int $distance,
        ?Location $location,
        ?Attendance $attendance,
    ): ClockAttempt {
        return ClockAttempt::query()->create([
            'user_id' => $user->id,
            'attendance_id' => $attendance?->id,
            'location_id' => $location?->id,
            'type' => $type,
            'result' => $result,
            'message' => $message,
            'latitude' => $punch->latitude,
            'longitude' => $punch->longitude,
            'accuracy_meters' => $punch->accuracy,
            'distance_meters' => $distance,
            'ip_address' => $punch->ipAddress,
            'user_agent' => $punch->userAgent,
        ]);
    }

    protected function humanizeMinutes(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return $rest === 0 ? $hours.'h' : sprintf('%dh %dm', $hours, $rest);
    }

    protected function humanizeDistance(int $meters): string
    {
        return $meters >= 1000
            ? sprintf('%.1fkm', $meters / 1000)
            : $meters.'m';
    }
}
