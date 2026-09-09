<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AttemptResult;
use App\Http\Controllers\Controller;
use App\Models\ClockAttempt;
use App\Models\Location;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class ClockAttemptController extends Controller
{
    /**
     * Every clock trial, successful or rejected, with its geolocation reading.
     */
    public function index(Request $request): Response
    {
        $result = $request->string('result')->toString();
        $search = $request->string('search')->toString();
        $range = $request->string('range')->toString() ?: '7d';
        $locationId = $request->string('location')->toString();

        $since = match ($range) {
            'today' => Carbon::now()->startOfDay(),
            '30d' => Carbon::now()->subDays(30),
            'all' => null,
            default => Carbon::now()->subDays(7),
        };

        $attempts = ClockAttempt::query()
            ->with(['user:id,name,employee_id,department_id', 'user.department:id,name', 'location:id,name,timezone'])
            ->when($since !== null, fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->when($result === 'rejected', fn (Builder $q) => $q->rejected())
            ->when($locationId !== '', fn (Builder $q) => $q->where('location_id', $locationId))
            ->when(
                $result !== '' && $result !== 'rejected' && $result !== 'all',
                fn (Builder $q) => $q->where('result', $result),
            )
            ->when($search !== '', fn (Builder $q) => $q->whereHas(
                'user',
                fn (Builder $u) => $u->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('employee_id', 'like', "%{$search}%"),
            ))
            ->latest()
            ->paginate(20)
            ->withQueryString()
            ->through(fn (ClockAttempt $a): array => [
                'id' => $a->id,
                'user' => [
                    'id' => $a->user->id,
                    'name' => $a->user->name,
                    'initials' => $a->user->initials,
                    'employee_id' => $a->user->employee_id,
                    'department' => $a->user->department?->name,
                ],
                'type' => $a->type->value,
                'type_label' => $a->type->label(),
                'result' => $a->result->value,
                'result_label' => $a->result->label(),
                'message' => $a->message,
                'latitude' => $a->latitude,
                'longitude' => $a->longitude,
                'accuracy_meters' => $a->accuracy_meters,
                'distance_meters' => $a->distance_meters,
                'ip_address' => $a->ip_address,
                'location' => $a->location?->name,
                'created_at' => $a->created_at
                    ->copy()
                    ->setTimezone(
                        $a->location !== null
                            ? $a->location->timezone
                            : config('app.timezone'),
                    )
                    ->toIso8601String(),
            ]);

        $counts = ClockAttempt::query()
            ->when($since !== null, fn (Builder $q) => $q->where('created_at', '>=', $since))
            ->when($locationId !== '', fn (Builder $q) => $q->where('location_id', $locationId))
            ->selectRaw('result, count(*) as total')
            ->groupBy('result')
            ->pluck('total', 'result');

        return Inertia::render('admin/ClockAttempts', [
            'attempts' => $attempts,
            'filters' => [
                'result' => $result ?: 'all',
                'search' => $search,
                'range' => $range,
                'location' => $locationId,
            ],
            'results' => AttemptResult::options(),
            'counts' => [
                'total' => (int) $counts->sum(),
                'success' => (int) $counts->get(AttemptResult::Success->value, 0),
                'rejected' => (int) $counts->sum() - (int) $counts->get(AttemptResult::Success->value, 0),
                'out_of_range' => (int) $counts->get(AttemptResult::OutOfRange->value, 0),
            ],
            'locations' => Location::query()
                ->orderBy('name')
                ->get(['id', 'name', 'radius_meters'])
                ->map(fn (Location $l): array => [
                    'value' => (string) $l->id,
                    'label' => $l->name,
                ])
                ->all(),
        ]);
    }
}
