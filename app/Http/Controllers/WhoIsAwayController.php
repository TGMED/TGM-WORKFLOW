<?php

namespace App\Http\Controllers;

use App\Enums\RequestStatus;
use App\Models\LeaveRequest;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who the company is missing, and when they are back. Approved leave only,
 * and without the reason it was asked for: this is a roster, not a record.
 */
class WhoIsAwayController extends Controller
{
    public function index(Request $request): Response
    {
        $window = $request->string('window')->toString() ?: 'today';
        $search = $request->string('search')->toString();
        $locationId = $request->string('location')->toString();

        $today = Carbon::now()->startOfDay();

        $end = match ($window) {
            '7d' => $today->copy()->addDays(6),
            '30d' => $today->copy()->addDays(29),
            default => $today->copy(),
        };

        $away = LeaveRequest::query()
            ->with(['user:id,name,employee_id,department_id,position,location_id', 'user.department:id,name', 'user.location:id,name', 'leaveType:id,name', 'reliefOfficer:id,name'])
            ->approved()
            ->overlapping($today, $end)
            ->when($locationId !== '', fn (Builder $q) => $q->whereHas(
                'user',
                fn (Builder $u) => $u->where('location_id', $locationId),
            ))
            ->when($search !== '', fn (Builder $q) => $q->whereHas(
                'user',
                fn (Builder $u) => $u->where('name', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%")
                    ->orWhereRelation('department', 'name', 'like', "%{$search}%"),
            ))
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        return Inertia::render('WhoIsAway', [
            'filters' => [
                'window' => $window,
                'search' => $search,
                'location' => $locationId,
            ],
            'today' => $today->toDateString(),
            'people' => $away
                ->map(fn (LeaveRequest $leave): array => $this->payload($leave, $today))
                ->values()
                ->all(),
            'stats' => $this->stats($today),
            'locations' => Location::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Location $location): array => [
                    'value' => (string) $location->id,
                    'label' => $location->name,
                ])
                ->all(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(LeaveRequest $leave, Carbon $today): array
    {
        $started = $leave->start_date->lessThanOrEqualTo($today);

        return [
            'id' => $leave->id,
            'user' => [
                'id' => $leave->user->id,
                'name' => $leave->user->name,
                'initials' => $leave->user->initials,
                'employee_id' => $leave->user->employee_id,
                'department' => $leave->user->department?->name,
                'position' => $leave->user->position,
                'location' => $leave->user->location?->name,
            ],
            'type' => $leave->leaveType->name,
            'start_date' => $leave->start_date->toDateString(),
            'end_date' => $leave->end_date->toDateString(),
            'range_label' => $leave->start_date->format('j M').' to '.$leave->end_date->format('j M Y'),
            'days' => $leave->days,
            // Away right now, as against booked for later in the window.
            'is_away' => $started,
            // Counted from the morning of the day they are back at their desk.
            'returns_on' => $leave->end_date->copy()->addDay()->toDateString(),
            'days_left' => $started
                ? (int) $today->diffInDays($leave->end_date, false) + 1
                : null,
            'starts_in' => $started
                ? null
                : (int) $today->diffInDays($leave->start_date, false),
            'relief_officer' => $leave->reliefOfficer?->name,
        ];
    }

    /**
     * Headline counts, each per person rather than per request so overlapping
     * bookings do not double up.
     *
     * @return array<string, int>
     */
    protected function stats(Carbon $today): array
    {
        return [
            'away_today' => $this->headcount($today, $today),
            'away_this_week' => $this->headcount($today, $today->copy()->addDays(6)),
            'back_tomorrow' => LeaveRequest::query()
                ->approved()
                ->where('end_date', $today->toDateString())
                ->distinct()
                ->count('user_id'),
            'pending' => LeaveRequest::query()
                ->where('status', RequestStatus::Pending->value)
                ->where('end_date', '>=', $today->toDateString())
                ->distinct()
                ->count('user_id'),
            'active_staff' => User::query()->active()->clocksIn()->count(),
        ];
    }

    protected function headcount(Carbon $start, Carbon $end): int
    {
        return LeaveRequest::query()
            ->approved()
            ->overlapping($start, $end)
            ->distinct()
            ->count('user_id');
    }
}
