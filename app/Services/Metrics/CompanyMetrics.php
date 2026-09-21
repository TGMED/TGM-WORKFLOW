<?php

namespace App\Services\Metrics;

use App\Enums\AttendanceStatus;
use App\Enums\EmploymentStatus;
use App\Enums\ExitReason;
use App\Enums\PayrollRunStatus;
use App\Enums\Permission;
use App\Enums\ReportStatus;
use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\Department;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\Report;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the company console counts.
 *
 * A service rather than a fat controller for two reasons: the numbers are worth
 * testing on their own, and grouping them here is what stops every panel
 * becoming its own set of queries. Panels that measure the same thing share
 * a query — one grouped read of this month's attendance feeds the headline, the
 * department table and the punctuality lists.
 */
class CompanyMetrics
{
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
            'movement' => $this->movement($today),
            'probation' => $this->probation($today),
            'punctuality' => $this->punctuality($monthAttendance),
            'payroll' => $viewer->hasPermission(Permission::ManagePayroll)
                ? $this->payroll()
                : null,
            'incidents' => $viewer->hasPermission(Permission::HandleReports)
                ? $this->incidents()
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

    // 8. What leave the company still owes.

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
