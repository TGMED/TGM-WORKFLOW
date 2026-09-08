<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExitReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffExitRequest;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\Attendance;
use App\Models\ClockAttempt;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Services\StaffExit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    public function __construct(protected StaffExit $exits) {}

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $status = $request->string('status')->toString();
        $department = $request->string('department')->toString();
        $locationId = $request->string('location')->toString();

        $monthStart = Carbon::now()->startOfMonth()->toDateString();

        $paginator = User::query()
            ->with(['location:id,name,city,timezone', 'role:id,slug,name'])
            ->when($search !== '', fn (Builder $q) => $q->where(function (Builder $q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%");
            }))
            ->when($status === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $q) => $q->where('is_active', false))
            ->when($department !== '', fn (Builder $q) => $q->where('department', $department))
            ->when($locationId === 'none', fn (Builder $q) => $q->whereNull('location_id'))
            ->when(
                $locationId !== '' && $locationId !== 'none',
                fn (Builder $q) => $q->where('location_id', $locationId),
            )
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();

        /** @var array<int, int> $ids */
        $ids = collect($paginator->items())->pluck('id')->all();

        $monthly = $this->monthlyTotals($ids, $monthStart);
        $todays = $this->todaysAttendance($ids);

        $staff = $paginator->through(fn (User $user): array => [
            'id' => $user->id,
            'employee_id' => $user->employee_id,
            'name' => $user->name,
            'email' => $user->email,
            'initials' => $user->initials,
            'role' => $user->role->slug,
            'role_label' => $user->role->name,
            'department' => $user->department,
            'position' => $user->position,
            'is_active' => $user->is_active,
            'location' => $user->location === null ? null : [
                'id' => $user->location->id,
                'name' => $user->location->name,
                'city' => $user->location->city,
            ],
            'late_this_month' => $monthly[$user->id]['late'] ?? 0,
            'present_this_month' => $monthly[$user->id]['present'] ?? 0,
            'clocks_in' => $user->clocksIn(),
            'today' => $user->clocksIn()
                ? $this->todayState($todays[$user->id] ?? null, $user->location?->timezone)
                : null,
        ]);

        return Inertia::render('admin/StaffIndex', [
            'staff' => $staff,
            'filters' => [
                'search' => $search,
                'status' => $status ?: 'all',
                'department' => $department,
                'location' => $locationId,
            ],
            'departments' => User::query()
                ->whereNotNull('department')
                ->distinct()
                ->orderBy('department')
                ->pluck('department'),
            'locations' => $this->locationOptions(),
            'roles' => Role::options(),
            'totals' => [
                'all' => User::query()->count(),
                'active' => User::query()->active()->count(),
                'inactive' => User::query()->where('is_active', false)->count(),
                'unassigned' => User::query()->whereNull('location_id')->count(),
            ],
        ]);
    }

    public function store(StoreStaffRequest $request): RedirectResponse
    {
        $user = User::query()->create($request->payload());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$user->name} has been added to the team.",
        ]);
    }

    public function show(Request $request, User $staff): Response
    {
        $staff->load('location', 'role');

        $timezone = $staff->location !== null
            ? $staff->location->timezone
            : config('app.timezone');
        $localNow = Carbon::now()->setTimezone($timezone);
        $monthStart = $localNow->copy()->startOfMonth();

        $attendances = Attendance::query()
            ->with('location:id,name')
            ->where('user_id', $staff->id)
            ->orderByDesc('work_date')
            ->limit(60)
            ->get();

        $thisMonth = $attendances->filter(
            fn (Attendance $a): bool => $a->work_date->greaterThanOrEqualTo($monthStart),
        );

        return Inertia::render('admin/StaffShow', [
            'staff' => [
                'id' => $staff->id,
                'employee_id' => $staff->employee_id,
                'name' => $staff->name,
                'email' => $staff->email,
                'initials' => $staff->initials,
                'phone' => $staff->phone,
                'department' => $staff->department,
                'position' => $staff->position,
                'role' => $staff->role->slug,
                'role_label' => $staff->role->name,
                'hired_at' => $staff->hired_at?->toDateString(),
                'is_active' => $staff->is_active,
                'deactivated_at' => $staff->deactivated_at?->toIso8601String(),
                'has_exited' => $staff->hasExited(),
                'exit_reason' => $staff->exit_reason?->value,
                'exit_reason_label' => $staff->exit_reason?->label(),
                'exit_reason_tone' => $staff->exit_reason?->tone(),
                'exit_date' => $staff->exit_date?->toDateString(),
                'exit_date_label' => $staff->exit_date?->format('j M Y'),
                'exit_note' => $staff->exit_note,
                'created_at' => $staff->created_at?->toIso8601String(),
                'location_id' => $staff->location_id,
                'clocks_in' => $staff->clocksIn(),
                'location' => $staff->location === null ? null : [
                    'id' => $staff->location->id,
                    'name' => $staff->location->name,
                    'address' => $staff->location->address,
                    'city' => $staff->location->city,
                    'work_starts_at' => substr($staff->location->work_starts_at, 0, 5),
                    'work_ends_at' => substr($staff->location->work_ends_at, 0, 5),
                    'timezone' => $staff->location->timezone,
                    'radius_meters' => $staff->location->radius_meters,
                ],
            ],
            'stats' => $this->monthStats($thisMonth),
            'attendances' => $attendances->map(fn (Attendance $a): array => [
                'id' => $a->id,
                'work_date' => $a->work_date->toDateString(),
                'day_label' => $a->work_date->format('D, j M Y'),
                'location_name' => $a->location?->name,
                'clocked_in_at' => $a->clocked_in_at?->copy()->setTimezone($timezone)->toIso8601String(),
                'clocked_out_at' => $a->clocked_out_at?->copy()->setTimezone($timezone)->toIso8601String(),
                'status' => $a->status->value,
                'status_label' => $a->status->label(),
                'excused' => $a->isExcused(),
                'late_minutes' => $a->late_minutes,
                'worked_minutes' => $a->worked_minutes,
                'break_minutes' => $a->break_minutes,
                'clock_in_distance' => $a->clock_in_distance,
            ])->values(),
            'attempts' => ClockAttempt::query()
                ->where('user_id', $staff->id)
                ->latest()
                ->limit(30)
                ->get()
                ->map(fn (ClockAttempt $a): array => [
                    'id' => $a->id,
                    'type_label' => $a->type->label(),
                    'result' => $a->result->value,
                    'result_label' => $a->result->label(),
                    'message' => $a->message,
                    'latitude' => $a->latitude,
                    'longitude' => $a->longitude,
                    'distance_meters' => $a->distance_meters,
                    'accuracy_meters' => $a->accuracy_meters,
                    'ip_address' => $a->ip_address,
                    'created_at' => $a->created_at->copy()->setTimezone($timezone)->toIso8601String(),
                ])->values(),
            'roles' => Role::options(),
            'locations' => $this->locationOptions(),
            'exit_reasons' => ExitReason::options(),
        ]);
    }

    public function update(UpdateStaffRequest $request, User $staff): RedirectResponse
    {
        $data = $request->payload();

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $staff->update($data);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$staff->name}'s profile has been updated.",
        ]);
    }

    /**
     * Walk somebody out: record why they went, and clear up behind them.
     *
     * @throws \Throwable
     */
    public function exit(StaffExitRequest $request, User $staff): RedirectResponse
    {
        if ($staff->id === $request->user()->id) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'You cannot record your own exit.',
            ]);
        }

        if (! $staff->is_active) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => "{$staff->name} has already left.",
            ]);
        }

        $outcome = $this->exits->record(
            $staff,
            $request->reason(),
            $request->lastWorkingDay(),
            $request->note(),
        );

        $message = "{$staff->name} has been marked as having left. ".
            'They can no longer sign in, and stop counting towards company figures.';

        if (($cleanup = $outcome->summary()) !== null) {
            $message .= ' '.$cleanup;
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => $message,
        ]);
    }

    /**
     * Put somebody back on the books, for the rehire and for the exit that
     * should never have been recorded.
     */
    public function reinstate(Request $request, User $staff): RedirectResponse
    {
        if ($staff->is_active) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => "{$staff->name} is already active.",
            ]);
        }

        $this->exits->reinstate($staff);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$staff->name} has been reinstated and can sign in again.",
        ]);
    }

    /**
     * @return array<int, array{value: int, label: string}>
     */
    protected function locationOptions(): array
    {
        return Location::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'city'])
            ->map(fn (Location $location): array => [
                'value' => $location->id,
                'label' => $location->city === null
                    ? $location->name
                    : "{$location->name}, {$location->city}",
            ])
            ->all();
    }

    /**
     * This month at a glance, with days an approved explanation has settled
     * left out of everything that counts against the person.
     *
     * @param  Collection<int, Attendance>  $month
     * @return array<string, mixed>
     */
    protected function monthStats(Collection $month): array
    {
        $present = $month->count();
        $late = $month->filter(fn (Attendance $a): bool => $a->countsAsLate());

        return [
            'days_present' => $present,
            'days_late' => $late->count(),
            'days_excused' => $month->filter(fn (Attendance $a): bool => $a->isExcused())->count(),
            'total_hours' => round((int) $month->sum('worked_minutes') / 60, 1),
            'late_minutes' => (int) $late->sum('late_minutes'),
            'punctuality' => $present > 0
                ? (int) round((($present - $late->count()) / $present) * 100)
                : 100,
        ];
    }

    /**
     * Present and late day counts for the listed staff, in one grouped query.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{present: int, late: int}>
     */
    protected function monthlyTotals(array $ids, string $monthStart): array
    {
        if ($ids === []) {
            return [];
        }

        return Attendance::query()
            ->whereIn('user_id', $ids)
            ->where('work_date', '>=', $monthStart)
            ->get(['id', 'user_id', 'status', 'excused_at'])
            ->groupBy('user_id')
            ->map(fn ($rows): array => [
                'present' => $rows->count(),
                'late' => $rows->filter(fn (Attendance $a): bool => $a->countsAsLate())->count(),
            ])
            ->all();
    }

    /**
     * Today's record per staff member. Sites can sit in different timezones, so
     * this spans both candidate dates and picks per user below.
     *
     * @param  array<int, int>  $ids
     * @return array<int, Attendance>
     */
    protected function todaysAttendance(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $now = Carbon::now();

        return Attendance::query()
            ->whereIn('user_id', $ids)
            ->whereIn('work_date', [
                $now->copy()->subDay()->toDateString(),
                $now->toDateString(),
                $now->copy()->addDay()->toDateString(),
            ])
            ->orderBy('work_date')
            ->get()
            ->keyBy('user_id')
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function todayState(?Attendance $attendance, ?string $timezone): ?array
    {
        if ($attendance === null) {
            return null;
        }

        $timezone ??= config('app.timezone');

        if ($attendance->work_date->toDateString() !== Carbon::now()->setTimezone($timezone)->toDateString()) {
            return null;
        }

        return [
            'status' => $attendance->status->value,
            'clocked_in_at' => $attendance->clocked_in_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'clocked_out_at' => $attendance->clocked_out_at?->copy()->setTimezone($timezone)->toIso8601String(),
            'late_minutes' => $attendance->late_minutes,
        ];
    }
}
