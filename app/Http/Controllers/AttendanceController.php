<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\ClockAttempt;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class AttendanceController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user()->load('location');
        $location = $user->location;
        $timezone = $location !== null ? $location->timezone : config('app.timezone');

        $requested = $request->string('month')->toString();

        $month = preg_match('/^\d{4}-\d{2}$/', $requested) === 1
            ? Carbon::createFromFormat('Y-m-d', $requested.'-01')->startOfMonth()
            : Carbon::now()->setTimezone($timezone)->startOfMonth();

        $records = Attendance::query()
            ->with('location:id,name')
            ->where('user_id', $user->id)
            ->between($month, $month->copy()->endOfMonth())
            ->orderByDesc('work_date')
            ->get()
            ->map(fn (Attendance $a): array => [
                'id' => $a->id,
                'work_date' => $a->work_date->toDateString(),
                'day_label' => $a->work_date->format('D, j M'),
                'location_name' => $a->location?->name,
                'clocked_in_at' => $a->clocked_in_at?->copy()->setTimezone($timezone)->toIso8601String(),
                'clocked_out_at' => $a->clocked_out_at?->copy()->setTimezone($timezone)->toIso8601String(),
                'status' => $a->status->value,
                'status_label' => $a->status->label(),
                'late_minutes' => $a->late_minutes,
                'worked_minutes' => $a->worked_minutes,
                'break_minutes' => $a->break_minutes,
                'clock_in_distance' => $a->clock_in_distance,
            ]);

        $attempts = ClockAttempt::query()
            ->where('user_id', $user->id)
            ->whereBetween('created_at', [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()])
            ->latest()
            ->limit(50)
            ->get()
            ->map(fn (ClockAttempt $a): array => [
                'id' => $a->id,
                'type' => $a->type->value,
                'type_label' => $a->type->label(),
                'result' => $a->result->value,
                'result_label' => $a->result->label(),
                'message' => $a->message,
                'distance_meters' => $a->distance_meters,
                'accuracy_meters' => $a->accuracy_meters,
                'created_at' => $a->created_at->copy()->setTimezone($timezone)->toIso8601String(),
            ]);

        return Inertia::render('Attendance', [
            'month' => $month->format('Y-m'),
            'month_label' => $month->format('F Y'),
            'records' => $records,
            'attempts' => $attempts,
            'summary' => [
                'days_present' => $records->count(),
                'days_late' => $records->where('status', 'late')->count(),
                'total_hours' => round((int) $records->sum('worked_minutes') / 60, 1),
                'late_minutes' => (int) $records->sum('late_minutes'),
            ],
            'location' => $location === null ? null : [
                'name' => $location->name,
                'address' => $location->address,
                'work_starts_at' => substr($location->work_starts_at, 0, 5),
                'timezone' => $location->timezone,
            ],
        ]);
    }
}
