<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\User;
use App\Services\LeaveBalance;
use App\Services\Metrics\CompanyMetrics;
use App\Services\Metrics\GroupMetrics;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        protected LeaveBalance $balances,
        protected CompanyMetrics $company,
        protected GroupMetrics $groups,
    ) {}

    public function __invoke(Request $request): Response
    {
        $user = $request->user()->load('location', 'headedDepartment', 'ledTeam');

        // Administrators run the clock rather than punch it, so they get the
        // company console instead of a personal dashboard. Company notices are
        // not here any more: they have a page of their own, and this one is
        // for numbers.
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
                'away' => null,
                'group' => $this->group($user),
                'metrics' => $this->company->all($user),
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
            'away' => $this->awaySummary($user, $localNow),
            // Set only for somebody who runs a department or a team, which is
            // what puts their people's numbers on their own dashboard.
            'group' => $this->group($user),
            // The company console, for an administrator who also works a
            // shift. Most people see nothing here.
            'metrics' => $user->hasPermission(Permission::ViewAdminDashboard)
                ? $this->company->all($user)
                : null,
        ]);
    }

    /**
     * The people this person is responsible for, if any.
     *
     * A head of department sees their whole department; a team lead sees their
     * team. Somebody who is both sees both sets together, since that is who
     * they answer for.
     *
     * @return array<string, mixed>|null
     */
    protected function group(User $user): ?array
    {
        if (! $user->managesAnyone()) {
            return null;
        }

        $department = $user->headedDepartment;
        $team = $user->ledTeam;

        $label = match (true) {
            $department !== null && $team !== null => "{$department->name} and {$team->name}",
            $department !== null => $department->name,
            default => (string) $team?->name,
        };

        return $this->groups->for(
            $user->managedUserIds(),
            $label,
            $department !== null ? 'department' : 'team',
        );
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

        // An approved explanation takes the day out of the tally entirely,
        // minutes included: a day the company has accepted is not a day
        // somebody should still be shown as owing time for.
        $countedLate = $month->filter(fn (Attendance $record): bool => $record->countsAsLate());

        $late = $countedLate->count();
        $totalMinutes = (int) $month->sum('worked_minutes');
        $lateMinutes = (int) $countedLate->sum('late_minutes');

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
     * Who else is out today. A count and a couple of names is all the
     * dashboard carries; the roster itself lives on its own page.
     *
     * @return array<string, mixed>
     */
    protected function awaySummary(User $user, Carbon $localNow): array
    {
        $colleagues = LeaveRequest::query()
            ->with('user:id,name')
            ->approved()
            ->overlapping($localNow, $localNow)
            ->where('user_id', '!=', $user->id)
            ->get()
            ->unique('user_id')
            ->values();

        return [
            'today' => $colleagues->count(),
            'names' => $colleagues->take(3)->map(fn (LeaveRequest $leave): string => $leave->user->name)->all(),
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
        return LeaveRequest::query()
            ->approved()
            ->overlapping(Carbon::now(), Carbon::now())
            ->distinct()
            ->count('user_id');
    }
}
