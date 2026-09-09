<?php

namespace App\Services\Metrics;

use App\Enums\AttendanceStatus;
use App\Enums\EmploymentStatus;
use App\Enums\ExitReason;
use App\Enums\PayrollRunStatus;
use App\Enums\Permission;
use App\Enums\ReportStatus;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\Department;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Report;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the company console counts.
 *
 * A service rather than a fat controller for two reasons: the numbers are worth
 * testing on their own, and grouping them here is what stops thirteen panels
 * becoming thirteen times the queries. Panels that measure the same thing share
 * a query — one grouped read of this month's attendance feeds the headline, the
 * department table and the punctuality lists.
 */
class CompanyMetrics
{
    /**
     * Role ids per permission, remembered for the life of one console build.
     * Three panels ask the same question, and it is the same answer each time.
     *
     * @var array<string, array<int, int>>
     */
    protected array $roleIds = [];

    /**
     * The whole console. Panels behind a permission are asked for by the
     * caller rather than decided here, so this stays a set of numbers.
     *
     * @return array<string, mixed>
     */
    public function all(User $viewer): array
    {
        $today = Carbon::now()->startOfDay();
        $monthStart = $today->copy()->startOfMonth();

        // Read once, shared by the headline, the departments table and both
        // punctuality lists.
        $monthAttendance = $this->attendanceThisMonth($monthStart, $today);

        return [
            'generated_at' => Carbon::now()->toIso8601String(),
            'month_label' => $today->format('F Y'),
            'headline' => $this->headline($today, $monthAttendance),
            'attendance' => $this->attendance($today),
            'departments' => $this->departments($monthAttendance, $today),
            'sites' => $this->sites(),
            'movement' => $this->movement($today),
            'probation' => $this->probation($today),
            'requests' => $this->requests($today, $monthStart),
            'leave_liability' => $this->leaveLiability($today->year),
            'punctuality' => $this->punctuality($monthAttendance),
            'payroll' => $viewer->hasPermission(Permission::ManagePayroll)
                ? $this->payroll()
                : null,
            'incidents' => $viewer->hasPermission(Permission::HandleReports)
                ? $this->incidents()
                : null,
            'access' => $viewer->hasPermission(Permission::ManageRoles)
                ? $this->access()
                : null,
        ];
    }

    // 1. The numbers along the top.

    /**
     * @param  Collection<int, AttendanceTally>  $monthAttendance
     * @return array<string, mixed>
     */
    protected function headline(Carbon $today, Collection $monthAttendance): array
    {
        $staff = User::query()->active()->clocksIn();
        $date = $today->toDateString();
        $monthStart = $today->copy()->startOfMonth();

        $present = (int) $monthAttendance->sum(fn (AttendanceTally $tally): int => $tally->daysPresent);
        $late = (int) $monthAttendance->sum(fn (AttendanceTally $tally): int => $tally->daysLate);

        return [
            'active_staff' => (clone $staff)->count(),
            'joined_this_month' => User::query()
                ->where('hired_at', '>=', $monthStart->toDateString())
                ->count(),
            'left_this_month' => User::query()
                ->departed()
                ->where('exit_date', '>=', $monthStart->toDateString())
                ->count(),
            'on_probation' => (clone $staff)
                ->where('employment_status', EmploymentStatus::Probation->value)
                ->count(),
            'unassigned_site' => (clone $staff)->whereNull('location_id')->count(),
            'unassigned_department' => (clone $staff)->whereNull('department_id')->count(),
            'clocked_in_today' => Attendance::query()
                ->ofActiveStaff()
                ->where('work_date', $date)
                ->whereNotNull('clocked_in_at')
                ->count(),
            'late_today' => Attendance::query()
                ->ofActiveStaff()
                ->where('work_date', $date)
                ->late()
                ->count(),
            'on_leave_today' => LeaveRequest::query()
                ->ofActiveStaff()
                ->approved()
                ->overlapping($today, $today)
                ->distinct()
                ->count('user_id'),
            'pending_requests' => LeaveRequest::query()->ofActiveStaff()->pending()->count()
                + LatenessRequest::query()->ofActiveStaff()->pending()->count(),
            'rejected_attempts_today' => ClockAttempt::query()
                ->ofActiveStaff()
                ->rejected()
                ->whereDate('created_at', $date)
                ->count(),
            'punctuality_this_month' => $present > 0
                ? (int) round((($present - $late) / $present) * 100)
                : null,
        ];
    }

    // 2. Attendance over time.

    /**
     * Thirty days of turnout, plus this month against last and the days that
     * went worst. One grouped query behind all three.
     *
     * @return array<string, mixed>
     */
    protected function attendance(Carbon $today): array
    {
        $start = $today->copy()->subDays(29);

        $rows = Attendance::query()
            ->ofActiveStaff()
            ->whereBetween('work_date', [$start->toDateString(), $today->toDateString()])
            ->selectRaw(
                'work_date, count(*) as total, '.
                'sum(case when status = ? and excused_at is null then 1 else 0 end) as late, '.
                'sum(case when clocked_in_at is not null then 1 else 0 end) as clocked_in',
                [AttendanceStatus::Late->value],
            )
            ->groupBy('work_date')
            ->get()
            ->keyBy(fn (Attendance $row): string => Carbon::parse($row->work_date)->toDateString());

        $days = [];

        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($today); $cursor = $cursor->addDay()) {
            $row = $rows->get($cursor->toDateString());
            $total = (int) ($row->total ?? 0);
            $late = (int) ($row->late ?? 0);

            $days[] = [
                'date' => $cursor->toDateString(),
                'label' => $cursor->format('D j'),
                'present' => $total,
                'late' => $late,
                'on_time' => $total - $late,
                'punctuality' => $total > 0 ? (int) round((($total - $late) / $total) * 100) : null,
            ];
        }

        // The days worth looking into: worst punctuality first, and only days
        // somebody actually worked.
        $worst = collect($days)
            ->filter(fn (array $day): bool => $day['present'] > 0 && $day['late'] > 0)
            ->sortBy('punctuality')
            ->take(5)
            ->values()
            ->all();

        return [
            'days' => $days,
            'worst_days' => $worst,
            'this_month' => $this->punctualityBetween(
                $today->copy()->startOfMonth(),
                $today,
            ),
            'last_month' => $this->punctualityBetween(
                $today->copy()->subMonthNoOverflow()->startOfMonth(),
                $today->copy()->subMonthNoOverflow()->endOfMonth(),
            ),
            'average_arrival' => $this->averageArrival($today->copy()->startOfMonth(), $today),
        ];
    }

    protected function punctualityBetween(Carbon $from, Carbon $to): ?int
    {
        $row = Attendance::query()
            ->ofActiveStaff()
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw(
                'count(*) as total, '.
                'sum(case when status = ? and excused_at is null then 1 else 0 end) as late',
                [AttendanceStatus::Late->value],
            )
            ->first();

        $total = (int) ($row->total ?? 0);

        return $total > 0
            ? (int) round((($total - (int) ($row->late ?? 0)) / $total) * 100)
            : null;
    }

    /**
     * What time people actually get in, as minutes past midnight. Read off the
     * stored timestamps, so it is the company's average rather than any one
     * site's.
     */
    protected function averageArrival(Carbon $from, Carbon $to): ?string
    {
        $times = Attendance::query()
            ->ofActiveStaff()
            ->whereBetween('work_date', [$from->toDateString(), $to->toDateString()])
            ->whereNotNull('clocked_in_at')
            ->pluck('clocked_in_at');

        if ($times->isEmpty()) {
            return null;
        }

        $minutes = (int) round($times
            ->map(fn ($at): int => Carbon::parse($at)->hour * 60 + Carbon::parse($at)->minute)
            ->average());

        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    // 3. The departments league table — the panel this whole feature is for.

    /**
     * @param  Collection<int, AttendanceTally>  $monthAttendance
     * @return array<int, array<string, mixed>>
     */
    protected function departments(Collection $monthAttendance, Carbon $today): array
    {
        $departments = Department::query()
            ->with('head:id,name')
            ->withCount([
                'members as headcount' => fn (Builder $query) => $query->where('is_active', true),
                'teams',
            ])
            ->orderBy('name')
            ->get();

        $byDepartment = User::query()
            ->active()
            ->clocksIn()
            ->whereNotNull('department_id')
            ->get(['id', 'department_id'])
            ->groupBy('department_id')
            ->map(fn (Collection $group): array => $group->pluck('id')->all());

        $clockedInToday = Attendance::query()
            ->ofActiveStaff()
            ->where('work_date', $today->toDateString())
            ->whereNotNull('clocked_in_at')
            ->pluck('user_id')
            ->flip();

        $onLeaveToday = LeaveRequest::query()
            ->ofActiveStaff()
            ->approved()
            ->overlapping($today, $today)
            ->pluck('user_id')
            ->flip();

        $openByUser = $this->openRequestsByUser();

        return $departments
            ->map(function (Department $department) use (
                $byDepartment,
                $monthAttendance,
                $clockedInToday,
                $onLeaveToday,
                $openByUser,
            ): array {
                $ids = $byDepartment->get($department->id, []);

                $present = 0;
                $late = 0;
                $lateMinutes = 0;

                foreach ($ids as $id) {
                    $tally = $monthAttendance->get($id);

                    if ($tally === null) {
                        continue;
                    }

                    $present += $tally->daysPresent;
                    $late += $tally->daysLate;
                    $lateMinutes += $tally->lateMinutes;
                }

                $headcount = count($ids);
                $in = count(array_filter($ids, fn (int $id): bool => $clockedInToday->has($id)));

                return [
                    'id' => $department->id,
                    'name' => $department->name,
                    'head' => $department->head?->name,
                    'headcount' => $headcount,
                    'teams' => $department->teams_count,
                    'clocked_in_today' => $in,
                    'on_leave_today' => count(array_filter(
                        $ids,
                        fn (int $id): bool => $onLeaveToday->has($id),
                    )),
                    'turnout' => $headcount > 0 ? (int) round(($in / $headcount) * 100) : 0,
                    'punctuality' => $present > 0
                        ? (int) round((($present - $late) / $present) * 100)
                        : null,
                    'late_minutes' => $lateMinutes,
                    'open_requests' => array_sum(array_map(
                        fn (int $id): int => $openByUser[$id] ?? 0,
                        $ids,
                    )),
                ];
            })
            ->all();
    }

    // 4. Sites.

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function sites(): array
    {
        $headcounts = User::query()
            ->active()
            ->clocksIn()
            ->whereNotNull('location_id')
            ->selectRaw('location_id, count(*) as total')
            ->groupBy('location_id')
            ->pluck('total', 'location_id');

        return Location::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(function (Location $location) use ($headcounts): array {
                $localDate = Carbon::now()->setTimezone($location->timezone)->toDateString();

                $records = Attendance::query()
                    ->ofActiveStaff()
                    ->where('location_id', $location->id)
                    ->where('work_date', $localDate)
                    ->get(['status', 'excused_at', 'clocked_in_at']);

                $headcount = (int) ($headcounts[$location->id] ?? 0);
                $in = $records->whereNotNull('clocked_in_at')->count();

                return [
                    'id' => $location->id,
                    'name' => $location->name,
                    'city' => $location->city,
                    'headcount' => $headcount,
                    'clocked_in' => $in,
                    'late' => $records->filter(
                        fn (Attendance $record): bool => $record->countsAsLate(),
                    )->count(),
                    'turnout' => $headcount > 0 ? (int) round(($in / $headcount) * 100) : 0,
                ];
            })
            ->all();
    }

    // 5. Who joined and who left.

    /**
     * Twelve months of joiners against leavers, and why people went.
     *
     * @return array<string, mixed>
     */
    protected function movement(Carbon $today): array
    {
        $start = $today->copy()->subMonthsNoOverflow(11)->startOfMonth();

        $joiners = $this->countByMonth(User::query()->whereNotNull('hired_at'), 'hired_at', $start);
        $leavers = $this->countByMonth(User::query()->departed()->whereNotNull('exit_date'), 'exit_date', $start);

        $months = [];

        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($today); $cursor = $cursor->addMonthNoOverflow()) {
            $key = $cursor->format('Y-m');

            $months[] = [
                'month' => $key,
                'label' => $cursor->format('M y'),
                'joined' => (int) ($joiners[$key] ?? 0),
                'left' => (int) ($leavers[$key] ?? 0),
            ];
        }

        $reasons = User::query()
            ->departed()
            ->whereNotNull('exit_reason')
            ->where('exit_date', '>=', $start->toDateString())
            ->selectRaw('exit_reason, count(*) as total')
            ->groupBy('exit_reason')
            ->pluck('total', 'exit_reason');

        return [
            'months' => $months,
            'reasons' => collect(ExitReason::cases())
                ->map(fn (ExitReason $reason): array => [
                    'value' => $reason->value,
                    'label' => $reason->label(),
                    'total' => (int) ($reasons[$reason->value] ?? 0),
                ])
                ->filter(fn (array $row): bool => $row['total'] > 0)
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Builder<User>  $query
     * @return Collection<string, mixed>
     */
    protected function countByMonth(Builder $query, string $column, Carbon $start): Collection
    {
        return $query
            ->where($column, '>=', $start->toDateString())
            ->get([$column])
            ->groupBy(fn (User $user): string => Carbon::parse($user->{$column})->format('Y-m'))
            ->map(fn (Collection $group): int => $group->count());
    }

    // 6. Probation.

    /**
     * Who is due to be confirmed soon, and who is already overdue.
     *
     * "Due" is read from the start date plus the probation length the company
     * sets, so somebody with no start date on file is left out rather than
     * guessed at.
     *
     * @return array<string, mixed>
     */
    protected function probation(Carbon $today): array
    {
        $months = (int) config('hr.probation_months', 6);

        $people = User::query()
            ->active()
            ->clocksIn()
            ->where('employment_status', EmploymentStatus::Probation->value)
            ->whereNotNull('hired_at')
            ->with('department:id,name')
            ->orderBy('hired_at')
            ->get(['id', 'name', 'hired_at', 'department_id', 'position']);

        $rows = $people
            ->map(function (User $person) use ($months, $today): array {
                $due = $person->hired_at->copy()->addMonthsNoOverflow($months);

                return [
                    'id' => $person->id,
                    'name' => $person->name,
                    'position' => $person->position,
                    'department' => $person->department?->name,
                    'hired_at' => $person->hired_at->toDateString(),
                    'due_at' => $due->toDateString(),
                    'due_label' => $due->format('j M Y'),
                    'days_until' => (int) $today->diffInDays($due, false),
                    'overdue' => $due->lessThan($today),
                ];
            })
            ->filter(fn (array $row): bool => $row['overdue'] || $row['days_until'] <= 60)
            ->sortBy('due_at')
            ->values();

        return [
            'months' => $months,
            'overdue' => $rows->where('overdue', true)->values()->all(),
            'due_soon' => $rows->where('overdue', false)->values()->all(),
            'total_on_probation' => $people->count(),
        ];
    }

    // 7. Requests.

    /**
     * Volume this month, what is still open, and how long a decision takes.
     *
     * @return array<string, mixed>
     */
    protected function requests(Carbon $today, Carbon $monthStart): array
    {
        return [
            'leave' => $this->moduleCounts(
                LeaveRequest::query()->ofActiveStaff()->where('created_at', '>=', $monthStart),
            ),
            'lateness' => $this->moduleCounts(
                LatenessRequest::query()->ofActiveStaff()->where('created_at', '>=', $monthStart),
            ),
            'median_decision_days' => $this->medianDecisionDays($monthStart),
            'oldest_pending' => $this->oldestPending(),
        ];
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    protected function moduleCounts(Builder $query): array
    {
        $counts = $query
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'pending' => (int) ($counts[RequestStatus::Pending->value] ?? 0),
            'approved' => (int) ($counts[RequestStatus::Approved->value] ?? 0),
            'rejected' => (int) ($counts[RequestStatus::Rejected->value] ?? 0),
            'total' => (int) $counts->sum(),
        ];
    }

    /**
     * The middle time to a decision, in days. A median rather than a mean:
     * one request left over a holiday would drag an average out of shape.
     */
    protected function medianDecisionDays(Carbon $since): ?float
    {
        $spans = LeaveRequest::query()
            ->ofActiveStaff()
            ->whereNotNull('decided_at')
            ->where('decided_at', '>=', $since)
            ->get(['created_at', 'decided_at'])
            ->map(fn (LeaveRequest $leave): float => (float) $leave->created_at->diffInDays($leave->decided_at))
            ->sort()
            ->values();

        if ($spans->isEmpty()) {
            return null;
        }

        $middle = intdiv($spans->count(), 2);

        return $spans->count() % 2 === 1
            ? round($spans[$middle], 1)
            : round(($spans[$middle - 1] + $spans[$middle]) / 2, 1);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function oldestPending(): array
    {
        return LeaveRequest::query()
            ->ofActiveStaff()
            ->with(['user:id,name', 'leaveType:id,name', 'supervisor:id,name', 'teamLead:id,name', 'head:id,name'])
            ->pending()
            ->orderBy('created_at')
            ->limit(8)
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                'id' => $leave->id,
                'staff' => $leave->user->name,
                'type' => $leave->leaveType->name,
                'days' => $leave->days,
                'range_label' => $leave->start_date->format('j M').' to '.$leave->end_date->format('j M'),
                'with' => ($leave->lineAwaitingUser() ?? $leave->supervisor)?->name,
                'waiting_days' => (int) $leave->created_at?->diffInDays(Carbon::now()),
                'created_at' => $leave->created_at?->toIso8601String(),
            ])
            ->all();
    }

    // 8. What leave the company still owes.

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function leaveLiability(int $year): array
    {
        $managerRoles = $this->rolesWith(Permission::ApproveRequests);

        $managers = User::query()->active()->clocksIn()
            ->whereHas('roles', fn (Builder $query) => $query->whereKey($managerRoles))
            ->count();
        $others = User::query()->active()->clocksIn()
            ->whereDoesntHave('roles', fn (Builder $query) => $query->whereKey($managerRoles))
            ->count();

        $committed = LeaveRequest::query()
            ->ofActiveStaff()
            ->committed()
            ->inYear($year)
            ->selectRaw('leave_type_id, sum(days) as days_used')
            ->groupBy('leave_type_id')
            ->pluck('days_used', 'leave_type_id');

        return LeaveType::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->filter(fn (LeaveType $type): bool => $type->isCapped())
            ->map(function (LeaveType $type) use ($managers, $others, $committed): array {
                $entitled = ($managers * ($type->days_per_year_manager ?? $type->days_per_year ?? 0))
                    + ($others * ($type->days_per_year ?? 0));
                $taken = (int) ($committed[$type->id] ?? 0);

                return [
                    'id' => $type->id,
                    'name' => $type->name,
                    'entitled' => $entitled,
                    'taken' => $taken,
                    'outstanding' => max(0, $entitled - $taken),
                    'used_percent' => $entitled > 0 ? (int) round(($taken / $entitled) * 100) : 0,
                ];
            })
            ->values()
            ->all();
    }

    // 9. Best and worst timekeeping.

    /**
     * @param  Collection<int, AttendanceTally>  $monthAttendance
     * @return array<string, mixed>
     */
    protected function punctuality(Collection $monthAttendance): array
    {
        $names = User::query()
            ->active()
            ->clocksIn()
            ->with('department:id,name')
            ->get(['id', 'name', 'department_id'])
            ->keyBy('id');

        $rows = $monthAttendance
            ->filter(fn (AttendanceTally $tally): bool => $names->has($tally->userId) && $tally->daysPresent > 0)
            ->map(function (AttendanceTally $tally) use ($names): array {
                /** @var User $person */
                $person = $names->get($tally->userId);

                return [
                    'id' => $person->id,
                    'name' => $person->name,
                    'department' => $person->department?->name,
                    'days_present' => $tally->daysPresent,
                    'days_late' => $tally->daysLate,
                    'late_minutes' => $tally->lateMinutes,
                    'punctuality' => $tally->punctuality(),
                ];
            })
            ->values();

        return [
            // Sorted by minutes lost rather than by days late: five minutes
            // twice is not the same problem as an hour once.
            'worst' => $rows
                ->filter(fn (array $row): bool => $row['late_minutes'] > 0)
                ->sortByDesc('late_minutes')
                ->take(5)
                ->values()
                ->all(),
            'best' => $rows
                ->filter(fn (array $row): bool => $row['days_present'] >= 5)
                ->sortByDesc('punctuality')
                ->sortByDesc('days_present')
                ->take(5)
                ->values()
                ->all(),
        ];
    }

    // 10. Payroll, behind its own permission.

    /**
     * @return array<string, mixed>|null
     */
    protected function payroll(): ?array
    {
        $last = PayrollRun::query()
            ->where('status', PayrollRunStatus::Finalised->value)
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        if ($last === null) {
            return ['last_run' => null];
        }

        $totals = fn (PayrollRun $run): object => Payslip::query()
            ->where('payroll_run_id', $run->id)
            ->selectRaw('count(*) as headcount, sum(gross_pay) as gross, sum(net_pay) as net')
            ->first();

        $current = $totals($last);

        $previous = PayrollRun::query()
            ->where('status', PayrollRunStatus::Finalised->value)
            ->where(fn (Builder $query) => $query
                ->where('year', '<', $last->year)
                ->orWhere(fn (Builder $q) => $q->where('year', $last->year)->where('month', '<', $last->month)))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->first();

        $before = $previous === null ? null : $totals($previous);

        return [
            'last_run' => [
                'id' => $last->id,
                'label' => Carbon::create($last->year, $last->month, 1)->format('F Y'),
                'headcount' => (int) ($current->headcount ?? 0),
                'gross' => round((float) ($current->gross ?? 0), 2),
                'net' => round((float) ($current->net ?? 0), 2),
                'finalised_at' => $last->finalised_at?->toIso8601String(),
            ],
            'previous' => $before === null ? null : [
                'label' => Carbon::create($previous->year, $previous->month, 1)->format('F Y'),
                'gross' => round((float) ($before->gross ?? 0), 2),
                'net' => round((float) ($before->net ?? 0), 2),
            ],
            'open_runs' => PayrollRun::query()
                ->where('status', '!=', PayrollRunStatus::Finalised->value)
                ->count(),
        ];
    }

    // 11. Incidents, behind its own permission.

    /**
     * @return array<string, mixed>
     */
    protected function incidents(): array
    {
        $counts = Report::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $oldestOpen = Report::query()
            ->whereIn('status', [ReportStatus::Submitted->value, ReportStatus::UnderReview->value])
            ->orderBy('created_at')
            ->first(['id', 'created_at']);

        return [
            'by_status' => collect(ReportStatus::cases())
                ->map(fn (ReportStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                    'total' => (int) ($counts[$status->value] ?? 0),
                ])
                ->all(),
            'open' => (int) ($counts[ReportStatus::Submitted->value] ?? 0)
                + (int) ($counts[ReportStatus::UnderReview->value] ?? 0),
            'oldest_open_days' => $oldestOpen === null
                ? null
                : (int) $oldestOpen->created_at?->diffInDays(Carbon::now()),
        ];
    }

    // 12. Who holds what, behind the roles permission.

    /**
     * @return array<string, mixed>
     */
    protected function access(): array
    {
        $roles = Role::query()
            ->with('rolePermissions')
            ->withCount(['users' => fn (Builder $query) => $query->where('is_active', true)])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return [
            // Somebody holding two roles is counted under both, which is what
            // makes this a picture of access rather than of headcount.
            'roles' => $roles
                ->map(fn (Role $role): array => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'users_count' => $role->users_count,
                    'permissions_count' => count($role->permissions()),
                    'holds_everything' => $role->slug === Role::SUPER_ADMIN,
                    'held_by_nobody' => $role->users_count === 0,
                ])
                ->all(),
            // The two worth naming outright: one reads what staff have raised
            // in confidence, the other reads what everybody earns.
            'sensitive' => [
                'reports' => $this->holdersOf(Permission::HandleReports),
                'payroll' => $this->holdersOf(Permission::ManagePayroll),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function holdersOf(Permission $permission): array
    {
        return User::query()
            ->active()
            ->whereHas('roles', fn (Builder $query) => $query->whereKey($this->rolesWith($permission)))
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * @return array<int, int>
     */
    protected function rolesWith(Permission $permission): array
    {
        return $this->roleIds[$permission->value] ??= Role::idsWithPermission($permission);
    }

    // Shared reads.

    /**
     * This month's attendance per person, grouped once and handed to every
     * panel that needs it.
     *
     * @return Collection<int, AttendanceTally>
     */
    protected function attendanceThisMonth(Carbon $from, Carbon $to): Collection
    {
        return AttendanceTally::over(
            Attendance::query()->ofActiveStaff(),
            $from->toDateString(),
            $to->toDateString(),
        );
    }

    /**
     * Open requests per person, both modules together.
     *
     * @return array<int, int>
     */
    protected function openRequestsByUser(): array
    {
        $totals = [];

        foreach ([LeaveRequest::class, LatenessRequest::class] as $model) {
            $rows = $model::query()
                ->ofActiveStaff()
                ->pending()
                ->selectRaw('user_id, count(*) as total')
                ->groupBy('user_id')
                ->pluck('total', 'user_id');

            foreach ($rows as $userId => $total) {
                $totals[$userId] = ($totals[$userId] ?? 0) + (int) $total;
            }
        }

        return $totals;
    }
}
