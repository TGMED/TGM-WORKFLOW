<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceStatus;
use App\Enums\RequestStatus;
use App\Models\Announcement;
use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\User;
use App\Services\Celebrations;
use App\Services\LeaveBalance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected LeaveBalance $balances,
        protected Celebrations $celebrations,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user()->load('location');

        // Super admins run the clock rather than punch it, so they get the
        // company console instead of a personal dashboard.
        if (! $user->clocksIn()) {
            return Inertia::render('Dashboard', [
                'clocksIn' => false,
                'selectableLocations' => [],
                'location' => null,
                'today' => null,
                'stats' => null,
                'trend' => [],
                'recent' => [],
                'lastAttempt' => null,
                'leave' => null,
                'overview' => $this->companyOverview(),
                'celebrations' => $this->celebrations->upcoming(Carbon::now()),
                'announcements' => $this->announcements(),
            ]);
        }

        $location = $user->location;

        // Without a site there is no timezone and no working day to measure
        // against; the page renders an explanatory state instead.
        $timezone = $location !== null ? $location->timezone : config('app.timezone');
        $localNow = Carbon::now()->setTimezone($timezone);
        $monthStart = $localNow->copy()->startOfMonth();

        $today = Attendance::query()
            ->where('user_id', $user->id)
            ->where('work_date', $localNow->toDateString())
            ->first();

        $month = Attendance::query()
            ->where('user_id', $user->id)
            ->between($monthStart, $localNow)
            ->orderByDesc('work_date')
            ->get();

        return Inertia::render('Dashboard', [
            'clocksIn' => true,
            // Only needed when they still have to claim a site.
            'selectableLocations' => $location === null ? $this->selectableLocations() : [],
            'location' => $location === null ? null : $this->locationPayload($location, $localNow),
            'today' => $today ? $this->attendancePayload($today, $timezone) : null,
            'stats' => $this->personalStats($month, $timezone),
            'trend' => $location === null ? [] : $this->trend($month, $localNow, $location),
            'recent' => $month->take(7)->map(fn (Attendance $a) => $this->attendancePayload($a, $timezone))->values(),
            'lastAttempt' => $this->lastRejectedAttempt($user),
            'leave' => $this->leaveSummary($user, $localNow),
            'overview' => null,
            // Both read the same for everyone: the whole company celebrates
            // together, and a notice is a notice wherever you sit.
            'celebrations' => $this->celebrations->upcoming($localNow),
            'announcements' => $this->announcements(),
        ]);
    }

    /**
     * The notices that are up right now, newest first with the pinned ones
     * held at the top. Capped, because the dashboard is not a noticeboard.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function announcements(int $limit = 4): array
    {
        return Announcement::query()
            ->with('author:id,name')
            ->live()
            ->inReadingOrder()
            ->limit($limit)
            ->get()
            ->map(fn (Announcement $announcement): array => [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'body' => $announcement->body,
                'is_pinned' => $announcement->is_pinned,
                'author' => $announcement->author?->name,
                'published_at' => $announcement->published_at?->toIso8601String(),
                'published_label' => $announcement->published_at?->diffForHumans(),
            ])
            ->all();
    }

    /**
     * Sites a staff member may claim for themselves.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function selectableLocations(): array
    {
        return Location::query()
            ->openToSignups()
            ->orderBy('name')
            ->get()
            ->map(fn (Location $location): array => [
                'id' => $location->id,
                'name' => $location->name,
                'address' => $location->address,
                'city' => $location->city,
                'work_starts_at' => substr($location->work_starts_at, 0, 5),
                'work_ends_at' => substr($location->work_ends_at, 0, 5),
                'radius_meters' => $location->radius_meters,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function locationPayload(Location $location, Carbon $localNow): array
    {
        return [
            'id' => $location->id,
            'name' => $location->name,
            'address' => $location->address,
            'city' => $location->city,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'radius_meters' => $location->radius_meters,
            'max_accuracy_meters' => $location->max_accuracy_meters,
            'work_starts_at' => substr($location->work_starts_at, 0, 5),
            'work_ends_at' => substr($location->work_ends_at, 0, 5),
            'grace_minutes' => $location->grace_minutes,
            'break_minutes' => $location->break_minutes,
            'timezone' => $location->timezone,
            'is_active' => $location->is_active,
            'configured' => $location->hasCoordinates(),
            'is_workday' => $location->isWorkday($localNow),
            'server_time' => $localNow->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function attendancePayload(Attendance $attendance, string $timezone): array
    {
        return [
            'id' => $attendance->id,
            'work_date' => $attendance->work_date->toDateString(),
            'clocked_in_at' => $attendance->clocked_in_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'clocked_out_at' => $attendance->clocked_out_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'status' => $attendance->status->value,
            'status_label' => $attendance->status->label(),
            'late_minutes' => $attendance->late_minutes,
            'worked_minutes' => $attendance->worked_minutes,
            'clock_in_distance' => $attendance->clock_in_distance,
            'is_open' => $attendance->isOpen(),
            'break_started_at' => $attendance->break_started_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'break_ended_at' => $attendance->break_ended_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'break_minutes' => $attendance->break_minutes,
            'on_break' => $attendance->isOnBreak(),
            'has_taken_break' => $attendance->hasTakenBreak(),
        ];
    }

    /**
     * @param  Collection<int, Attendance>  $month
     * @return array<string, mixed>
     */
    protected function personalStats(Collection $month, string $timezone): array
    {
        $present = $month->count();
        $late = $month->where('status', AttendanceStatus::Late)->count();
        $totalMinutes = (int) $month->sum('worked_minutes');
        $lateMinutes = (int) $month->sum('late_minutes');

        $averageArrival = null;
        $withClockIn = $month->filter(fn (Attendance $a) => $a->clocked_in_at !== null);

        if ($withClockIn->isNotEmpty()) {
            $averageSeconds = (int) round($withClockIn->avg(function (Attendance $a) use ($timezone): int {
                $local = $a->clocked_in_at->copy()->setTimezone($timezone);

                return $local->hour * 3600 + $local->minute * 60 + $local->second;
            }));

            $averageArrival = sprintf('%02d:%02d', intdiv($averageSeconds, 3600), intdiv($averageSeconds % 3600, 60));
        }

        return [
            'days_present' => $present,
            'days_late' => $late,
            'days_on_time' => $present - $late,
            'punctuality' => $present > 0 ? (int) round((($present - $late) / $present) * 100) : 100,
            'total_hours' => round($totalMinutes / 60, 1),
            'late_minutes' => $lateMinutes,
            'average_arrival' => $averageArrival,
        ];
    }

    /**
     * Per-day arrival offset (minutes relative to the site's opening time).
     *
     * @param  Collection<int, Attendance>  $month
     * @return array<int, array<string, mixed>>
     */
    protected function trend(Collection $month, Carbon $localNow, Location $location): array
    {
        $byDate = $month->keyBy(fn (Attendance $a) => $a->work_date->toDateString());
        $days = [];

        for ($cursor = $localNow->copy()->subDays(13); $cursor->lessThanOrEqualTo($localNow); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();
            /** @var Attendance|null $attendance */
            $attendance = $byDate->get($key);

            $offset = null;

            if ($attendance?->clocked_in_at !== null) {
                $local = $attendance->clocked_in_at->copy()->setTimezone($location->timezone);
                $offset = (int) $location->startOfWorkFor($local)->diffInMinutes($local, false);
            }

            $days[] = [
                'date' => $key,
                'label' => $cursor->format('D'),
                'offset' => $offset,
                'status' => $attendance?->status->value,
                'is_workday' => $location->isWorkday($cursor),
            ];
        }

        return $days;
    }

    /**
     * A short read on where this person stands with leave: what is left, what
     * is booked next, and what is still with an approver. Deliberately three
     * lines rather than a second dashboard.
     *
     * @return array<string, mixed>
     */
    protected function leaveSummary(User $user, Carbon $localNow): array
    {
        $balances = $this->balances->summary($user, $localNow->year);

        $next = LeaveRequest::query()
            ->with('leaveType')
            ->where('user_id', $user->id)
            ->where('status', RequestStatus::Approved->value)
            ->where('end_date', '>=', $localNow->toDateString())
            ->orderBy('start_date')
            ->first();

        return [
            'balances' => array_map(fn (array $balance): array => [
                'id' => $balance['id'],
                'name' => $balance['name'],
                'allowance' => $balance['allowance'],
                'remaining' => $balance['remaining'],
            ], $balances),
            'next' => $next === null ? null : [
                'type' => $next->leaveType->name,
                'days' => $next->days,
                'start_date' => $next->start_date->toDateString(),
                'range_label' => $next->start_date->format('j M').' to '.$next->end_date->format('j M'),
                // Already under way rather than still to come.
                'started' => $next->start_date->lessThanOrEqualTo($localNow),
            ],
            'pending' => LeaveRequest::query()
                ->where('user_id', $user->id)
                ->where('status', RequestStatus::Pending->value)
                ->count(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function lastRejectedAttempt(User $user): ?array
    {
        $attempt = ClockAttempt::query()
            ->where('user_id', $user->id)
            ->rejected()
            ->latest()
            ->first();

        if ($attempt === null || $attempt->created_at->lessThan(Carbon::now()->subDay())) {
            return null;
        }

        return [
            'result' => $attempt->result->value,
            'label' => $attempt->result->label(),
            'message' => $attempt->message,
            'distance_meters' => $attempt->distance_meters,
            'created_at' => $attempt->created_at->toIso8601String(),
        ];
    }

    /**
     * People whose approved leave covers today. Counted per person rather than
     * per request, so overlapping bookings do not double up.
     */
    protected function onLeaveToday(): int
    {
        $today = Carbon::now()->toDateString();

        return LeaveRequest::query()
            ->where('status', RequestStatus::Approved->value)
            ->where('start_date', '<=', $today)
            ->where('end_date', '>=', $today)
            ->distinct()
            ->count('user_id');
    }

    /**
     * Company-wide snapshot shown to super admins, broken down by site because
     * each one keeps its own working day.
     *
     * @return array<string, mixed>
     */
    protected function companyOverview(): array
    {
        $locations = Location::query()->active()->orderBy('name')->get();

        $activeStaff = User::query()->active()->clocksIn()->count();
        $clockedIn = 0;
        $lateToday = 0;
        $rejectedToday = 0;
        $sites = [];

        foreach ($locations as $location) {
            $today = Carbon::now()->setTimezone($location->timezone)->toDateString();

            $records = Attendance::query()
                ->where('location_id', $location->id)
                ->where('work_date', $today)
                ->get(['id', 'status', 'clocked_in_at']);

            $headcount = User::query()
                ->active()
                ->clocksIn()
                ->where('location_id', $location->id)
                ->count();

            $in = $records->whereNotNull('clocked_in_at')->count();
            $late = $records->where('status', AttendanceStatus::Late)->count();

            $rejected = ClockAttempt::query()
                ->rejected()
                ->where('location_id', $location->id)
                ->whereDate('created_at', $today)
                ->count();

            $clockedIn += $in;
            $lateToday += $late;
            $rejectedToday += $rejected;

            $sites[] = [
                'id' => $location->id,
                'name' => $location->name,
                'city' => $location->city,
                'headcount' => $headcount,
                'clocked_in' => $in,
                'late' => $late,
                'rejected' => $rejected,
                'work_starts_at' => substr($location->work_starts_at, 0, 5),
                'timezone' => $location->timezone,
                'attendance_rate' => $headcount > 0 ? (int) round(($in / $headcount) * 100) : 0,
            ];
        }

        return [
            'active_staff' => $activeStaff,
            'locations' => count($sites),
            'on_leave_today' => $this->onLeaveToday(),
            'clocked_in_today' => $clockedIn,
            'late_today' => $lateToday,
            'still_out' => max(0, $activeStaff - $clockedIn),
            'rejected_attempts_today' => $rejectedToday,
            'unassigned_staff' => User::query()->active()->clocksIn()->whereNull('location_id')->count(),
            'sites' => $sites,
        ];
    }
}
