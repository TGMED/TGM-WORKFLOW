<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocationRequest;
use App\Models\Attendance;
use App\Models\Location;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class LocationController extends Controller
{
    public function index(): Response
    {
        $locations = Location::query()->orderBy('name')->get();

        // Only people who actually clock in count towards a site's headcount.
        $staffCounts = User::query()
            ->clocksIn()
            ->whereNotNull('location_id')
            ->get(['id', 'location_id', 'is_active'])
            ->groupBy('location_id');

        return Inertia::render('admin/Locations', [
            'locations' => $locations->map(fn (Location $location): array => [
                ...$this->payload($location),
                'staff_count' => $staffCounts->get($location->id)?->count() ?? 0,
                'active_staff_count' => $staffCounts->get($location->id)?->where('is_active', true)->count() ?? 0,
                'today' => $this->todaySnapshot($location),
            ])->all(),
            'timezones' => timezone_identifiers_list(),
            'unassigned_staff' => User::query()->clocksIn()->whereNull('location_id')->count(),
        ]);
    }

    public function store(LocationRequest $request): RedirectResponse
    {
        $location = Location::query()->create($request->validated());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$location->name} has been added. Staff can now pick it at signup.",
        ]);
    }

    public function update(LocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($request->validated());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$location->name} updated. Punches are now checked against it.",
        ]);
    }

    /**
     * Retire or restore a site. Staff keep their history either way.
     */
    public function toggle(Location $location): RedirectResponse
    {
        $activating = ! $location->is_active;

        $location->update(['is_active' => $activating]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $activating
                ? "{$location->name} is active again."
                : "{$location->name} is retired. Its staff can no longer clock in there.",
        ]);
    }

    /**
     * Move every staff member from one site to another, so a site can be
     * retired without stranding people.
     */
    public function reassign(Request $request, Location $location): RedirectResponse
    {
        $validated = $request->validate([
            'to_location_id' => ['required', 'integer', 'exists:locations,id'],
        ]);

        $target = Location::query()
            ->whereKey($validated['to_location_id'])
            ->firstOrFail();

        if ($target->id === $location->id) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Pick a different site to move staff to.',
            ]);
        }

        $moved = User::query()
            ->where('location_id', $location->id)
            ->update(['location_id' => $target->id]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Moved {$moved} staff from {$location->name} to {$target->name}.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function payload(Location $location): array
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
            'workdays' => $location->workdayNumbers(),
            'timezone' => $location->timezone,
            'is_active' => $location->is_active,
            'accepts_signups' => $location->accepts_signups,
            'has_coordinates' => $location->hasCoordinates(),
        ];
    }

    /**
     * @return array<string, int>
     */
    protected function todaySnapshot(Location $location): array
    {
        $today = Carbon::now()->setTimezone($location->timezone)->toDateString();

        $records = Attendance::query()
            ->where('location_id', $location->id)
            ->where('work_date', $today)
            ->get(['id', 'status', 'clocked_in_at']);

        return [
            'clocked_in' => $records->whereNotNull('clocked_in_at')->count(),
            'late' => $records->where('status', AttendanceStatus::Late)->count(),
        ];
    }
}
