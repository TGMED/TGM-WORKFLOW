<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Enums\EmploymentStatus;
use App\Enums\Permission;
use App\Enums\RequestStatus;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Audit;
use App\Models\ClockAttempt;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Support\AuditTrail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The company console: headcount, attendance, what leave is owed and what is
 * waiting on somebody. Deliberately a read: every number here links out to the
 * page that can act on it.
 */
class AdminDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $today = Carbon::now()->startOfDay();

        return Inertia::render('admin/Dashboard', [
            'headline' => $this->headline($today),
            'sites' => $this->sites(),
            'attendance_trend' => $this->attendanceTrend($today),
            'leave_liability' => $this->leaveLiability($today->year),
            'requests' => $this->requests($today),
            'roles' => $this->roles(),
            'oldest_pending' => $this->oldestPending(),
            // Only for somebody who may see the trail at all; everyone else
            // gets a panel that is simply not there.
            'recent_activity' => $request->user()->hasPermission(Permission::ViewAuditTrail)
                ? $this->recentActivity()
                : null,
        ]);
    }

    /**
     * The numbers along the top.
     *
     * @return array<string, mixed>
     */
    protected function headline(Carbon $today): array
    {
        $staff = User::query()->active()->clocksIn();

        $clockedIn = Attendance::query()
            ->ofActiveStaff()
            ->where('work_date', $today->toDateString())
            ->whereNotNull('clocked_in_at')
            ->count();

        return [
            'active_staff' => (clone $staff)->count(),
            'on_probation' => (clone $staff)
                ->where('employment_status', EmploymentStatus::Probation->value)
                ->count(),
            'unassigned' => (clone $staff)->whereNull('location_id')->count(),
            'sites' => Location::query()->active()->count(),
            'clocked_in_today' => $clockedIn,
            'late_today' => Attendance::query()
                ->ofActiveStaff()
                ->where('work_date', $today->toDateString())
                ->late()
                ->count(),
            'on_leave_today' => LeaveRequest::query()
                ->ofActiveStaff()
                ->approved()
                ->overlapping($today, $today)
                ->distinct()
                ->count('user_id'),
            'pending_requests' => LeaveRequest::query()->ofActiveStaff()->where('status', RequestStatus::Pending->value)->count()
                + LatenessRequest::query()->ofActiveStaff()->where('status', RequestStatus::Pending->value)->count(),
            'rejected_attempts_today' => ClockAttempt::query()
                ->ofActiveStaff()
                ->rejected()
                ->whereDate('created_at', $today->toDateString())
                ->count(),
        ];
    }

    /**
     * Headcount and turnout per site. Each site keeps its own working day, so
     * "today" is asked of each one in its own timezone.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function sites(): array
    {
        return Location::query()
            ->active()
            ->orderBy('name')
            ->get()
            ->map(function (Location $location): array {
                $localDate = Carbon::now()->setTimezone($location->timezone)->toDateString();

                $headcount = User::query()
                    ->active()
                    ->clocksIn()
                    ->where('location_id', $location->id)
                    ->count();

                $records = Attendance::query()
                    ->ofActiveStaff()
                    ->where('location_id', $location->id)
                    ->where('work_date', $localDate)
                    ->get(['status', 'excused_at', 'clocked_in_at']);

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

    /**
     * Fourteen days of company turnout, on time against late. One grouped
     * query rather than one per day.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function attendanceTrend(Carbon $today): array
    {
        $start = $today->copy()->subDays(13);

        $rows = Attendance::query()
            ->ofActiveStaff()
            ->whereBetween('work_date', [$start->toDateString(), $today->toDateString()])
            ->selectRaw(
                'work_date, count(*) as total, '.
                'sum(case when status = ? and excused_at is null then 1 else 0 end) as late',
                [AttendanceStatus::Late->value],
            )
            ->groupBy('work_date')
            ->get()
            ->keyBy(fn (Attendance $row): string => Carbon::parse($row->work_date)->toDateString());

        $days = [];

        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($today); $cursor = $cursor->addDay()) {
            $key = $cursor->toDateString();
            $row = $rows->get($key);
            $total = (int) ($row->total ?? 0);
            $late = (int) ($row->late ?? 0);

            $days[] = [
                'date' => $key,
                'label' => $cursor->format('D j'),
                'present' => $total,
                'late' => $late,
                'on_time' => $total - $late,
            ];
        }

        return $days;
    }

    /**
     * Days of leave the company still owes, per capped type.
     *
     * Worked out from headcount rather than per person: managers and everyone
     * else draw different allowances, so the entitlement is the two groups
     * multiplied out, less what has already been taken or promised.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function leaveLiability(int $year): array
    {
        $managerRoles = Role::idsWithPermission(Permission::ApproveRequests);

        $managers = User::query()->active()->clocksIn()->whereIn('role_id', $managerRoles)->count();
        $others = User::query()->active()->clocksIn()->whereNotIn('role_id', $managerRoles)->count();

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
                $managerDays = $type->days_per_year_manager ?? $type->days_per_year ?? 0;
                $standardDays = $type->days_per_year ?? 0;

                $entitled = ($managers * $managerDays) + ($others * $standardDays);
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

    /**
     * Request volume this month, and what is still open.
     *
     * @return array<string, mixed>
     */
    protected function requests(Carbon $today): array
    {
        $monthStart = $today->copy()->startOfMonth();

        return [
            'leave' => $this->moduleCounts(
                LeaveRequest::query()->ofActiveStaff()->where('created_at', '>=', $monthStart),
            ),
            'lateness' => $this->moduleCounts(
                LatenessRequest::query()->ofActiveStaff()->where('created_at', '>=', $monthStart),
            ),
            'month_label' => $today->format('F Y'),
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
     * Headcount per role, so it is obvious at a glance if a role nobody holds
     * is carrying permissions, or if everybody is an administrator.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function roles(): array
    {
        return Role::query()
            ->with('rolePermissions')
            ->withCount(['users' => fn ($query) => $query->where('is_active', true)])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'name' => $role->name,
                'users_count' => $role->users_count,
                'permissions_count' => count($role->permissions()),
                'holds_everything' => $role->slug === Role::SUPER_ADMIN,
            ])
            ->all();
    }

    /**
     * Requests that have been waiting longest. The queue that needs chasing,
     * rather than the queue in general.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function oldestPending(): array
    {
        return LeaveRequest::query()
            ->ofActiveStaff()
            ->with(['user:id,name', 'leaveType:id,name', 'supervisor:id,name'])
            ->where('status', RequestStatus::Pending->value)
            ->orderBy('created_at')
            ->limit(6)
            ->get()
            ->map(fn (LeaveRequest $leave): array => [
                'id' => $leave->id,
                'staff' => $leave->user->name,
                'type' => $leave->leaveType->name,
                'days' => $leave->days,
                'range_label' => $leave->start_date->format('j M').' to '.$leave->end_date->format('j M'),
                'with' => $leave->supervisor?->name,
                'waiting_days' => (int) $leave->created_at?->diffInDays(Carbon::now()),
                'created_at' => $leave->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The last few changes anyone made, as a way into the full trail.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function recentActivity(): array
    {
        return Audit::query()
            ->with('user:id,name')
            ->newestFirst()
            ->limit(8)
            ->get()
            ->map(fn (Audit $audit): array => AuditTrail::payload($audit))
            ->all();
    }
}
