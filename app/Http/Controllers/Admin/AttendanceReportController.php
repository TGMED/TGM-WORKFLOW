<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceReportController extends Controller
{
    /**
     * A span longer than this is almost always a typed-in mistake, and it is
     * enough rows to hurt, so the window is clamped rather than refused.
     */
    private const MAX_DAYS = 366;

    /**
     * Attendance for every member of staff over a chosen date range.
     */
    public function index(Request $request): Response
    {
        [$from, $to] = $this->range($request);

        $search = $request->string('search')->toString();
        $department = $request->string('department')->toString();
        $locationId = $request->string('location')->toString();

        // Leavers are off the report unless they are asked for by name. A
        // report read as "how are we doing" should answer for the people who
        // are still here.
        $status = $request->string('status')->toString() ?: 'active';

        $paginator = $this->staff($search, $department, $locationId, $status)
            ->with('location:id,name,city,timezone,workdays')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        /** @var array<int, int> $ids */
        $ids = collect($paginator->items())->pluck('id')->all();

        $totals = $this->totalsFor($ids, $from, $to);
        $expected = $this->expectedDays($from, $to);

        $rows = $paginator->through(function (User $user) use ($totals, $expected): array {
            $row = $totals[$user->id] ?? [];
            $present = $row['days_present'] ?? 0;
            $late = $row['days_late'] ?? 0;
            $grace = $row['days_grace'] ?? 0;
            $excused = $row['days_excused'] ?? 0;
            $due = $user->location_id === null ? null : ($expected[$user->location_id] ?? null);

            return [
                'id' => $user->id,
                'employee_id' => $user->employee_id,
                'name' => $user->name,
                'initials' => $user->initials,
                'department' => $user->department,
                'position' => $user->position,
                'is_active' => $user->is_active,
                'location' => $user->location?->name,
                'days_present' => $present,
                'days_late' => $late,
                'days_excused' => $excused,
                'days_grace' => $grace,
                'days_expected' => $due,
                'days_absent' => $due === null ? null : max(0, $due - $present),
                'late_minutes' => $row['late_minutes'] ?? 0,
                'worked_minutes' => $row['worked_minutes'] ?? 0,
                'break_minutes' => $row['break_minutes'] ?? 0,
                'open_days' => $row['open_days'] ?? 0,
                'last_seen' => ($row['last_seen'] ?? null)?->format('j M Y'),
                'punctuality' => $present > 0
                    ? (int) round((($present - $late) / $present) * 100)
                    : null,
            ];
        });

        return Inertia::render('admin/AttendanceReport', [
            'rows' => $rows,
            'filters' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'search' => $search,
                'department' => $department,
                'location' => $locationId,
                'status' => $status,
            ],
            'range_label' => $this->rangeLabel($from, $to),
            'range_days' => (int) $from->diffInDays($to) + 1,
            'summary' => $this->summary($search, $department, $locationId, $status, $from, $to),
            'departments' => User::query()
                ->whereNotNull('department')
                ->distinct()
                ->orderBy('department')
                ->pluck('department'),
            'locations' => Location::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Location $l): array => [
                    'value' => (string) $l->id,
                    'label' => $l->name,
                ])
                ->all(),
        ]);
    }

    /**
     * The requested window, defaulting to the month so far. Bad or reversed
     * dates are corrected instead of throwing the whole report away.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $today = Carbon::now()->startOfDay();

        $from = $this->date($request->string('from')->toString()) ?? $today->copy()->startOfMonth();
        $to = $this->date($request->string('to')->toString()) ?? $today->copy();

        if ($from->greaterThan($to)) {
            [$from, $to] = [$to, $from];
        }

        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS - 1);
        }

        return [$from, $to];
    }

    private function date(string $value): ?Carbon
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }

        return rescue(
            fn (): Carbon => Carbon::createFromFormat('Y-m-d', $value)->startOfDay(),
            null,
            report: false,
        );
    }

    private function rangeLabel(Carbon $from, Carbon $to): string
    {
        if ($from->isSameDay($to)) {
            return $from->format('j M Y');
        }

        $left = $from->year === $to->year ? $from->format('j M') : $from->format('j M Y');

        return "{$left} – {$to->format('j M Y')}";
    }

    /**
     * Staff the report covers: everyone who punches a clock, admins aside,
     * and by default only those still with the company.
     *
     * @return Builder<User>
     */
    private function staff(string $search, string $department, string $locationId, string $status): Builder
    {
        return User::query()
            ->whereRelation('role', 'slug', '!=', Role::SUPER_ADMIN)
            ->when($status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%");
            }))
            ->when($department !== '', fn (Builder $q) => $q->where('department', $department))
            ->when($locationId === 'none', fn (Builder $q) => $q->whereNull('location_id'))
            ->when(
                $locationId !== '' && $locationId !== 'none',
                fn (Builder $q) => $q->where('location_id', $locationId),
            );
    }

    /**
     * Per-person totals over the range, in one grouped query.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{days_present: int, days_late: int, days_excused: int, days_grace: int, late_minutes: int, worked_minutes: int, break_minutes: int, open_days: int, last_seen: Carbon|null}>
     */
    private function totalsFor(array $ids, Carbon $from, Carbon $to): array
    {
        if ($ids === []) {
            return [];
        }

        return Attendance::query()
            ->whereIn('user_id', $ids)
            ->between($from, $to)
            ->get(['user_id', 'work_date', 'status', 'excused_at', 'late_minutes', 'worked_minutes', 'break_minutes', 'clocked_out_at'])
            ->groupBy('user_id')
            ->map(function (Collection $rows): array {
                $late = $rows->filter(fn (Attendance $row): bool => $row->countsAsLate());

                return [
                    'days_present' => $rows->count(),
                    'days_late' => $late->count(),
                    'days_excused' => $rows->filter(fn (Attendance $row): bool => $row->isExcused())->count(),
                    'days_grace' => $rows->where('status', AttendanceStatus::Grace)->count(),
                    'late_minutes' => (int) $late->sum('late_minutes'),
                    'worked_minutes' => (int) $rows->sum('worked_minutes'),
                    'break_minutes' => (int) $rows->sum('break_minutes'),
                    'open_days' => $rows->whereNull('clocked_out_at')->count(),
                    'last_seen' => $rows->max('work_date'),
                ];
            })
            ->all();
    }

    /**
     * Workdays falling inside the range, per site. Sites keep their own week,
     * so a Saturday site is not marked absent for working one.
     *
     * @return array<int, int>
     */
    private function expectedDays(Carbon $from, Carbon $to): array
    {
        $days = [];

        for ($day = $from->copy(); $day->lessThanOrEqualTo($to); $day->addDay()) {
            $days[] = $day->isoWeekday();
        }

        return Location::query()
            ->get(['id', 'workdays'])
            ->mapWithKeys(fn (Location $location): array => [
                $location->id => count(array_filter(
                    $days,
                    fn (int $weekday): bool => in_array($weekday, $location->workdayNumbers(), true),
                )),
            ])
            ->all();
    }

    /**
     * Headline figures across everyone the filters match, not just this page.
     *
     * @return array<string, int|float>
     */
    private function summary(
        string $search,
        string $department,
        string $locationId,
        string $status,
        Carbon $from,
        Carbon $to,
    ): array {
        $matching = $this->staff($search, $department, $locationId, $status);

        $records = Attendance::query()
            ->whereIn('user_id', $matching->clone()->select('users.id'))
            ->between($from, $to)
            ->get(['user_id', 'status', 'excused_at', 'late_minutes', 'worked_minutes']);

        $present = $records->count();
        $countedLate = $records->filter(fn (Attendance $row): bool => $row->countsAsLate());
        $late = $countedLate->count();

        return [
            'staff' => $matching->clone()->count(),
            'days_present' => $present,
            'days_late' => $late,
            'days_excused' => $records->filter(fn (Attendance $row): bool => $row->isExcused())->count(),
            'days_grace' => $records->where('status', AttendanceStatus::Grace)->count(),
            'late_minutes' => (int) $countedLate->sum('late_minutes'),
            'total_hours' => round((int) $records->sum('worked_minutes') / 60, 1),
            'punctuality' => $present > 0 ? (int) round((($present - $late) / $present) * 100) : 100,
        ];
    }
}
