<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicHolidayRequest;
use App\Models\Location;
use App\Models\PublicHoliday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The days off, company-wide or for some sites. Kept with the sites, since a
 * holiday is a day taken out of a site's week.
 */
class PublicHolidayController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) $request->integer('year', Carbon::now()->year);
        $today = Carbon::now()->startOfDay();

        $holidays = PublicHoliday::query()
            ->with('locations:id,name')
            ->whereYear('date', $year)
            ->orderBy('date')
            ->get();

        return Inertia::render('admin/Holidays', [
            'year' => $year,
            'holidays' => $holidays
                ->map(fn (PublicHoliday $holiday): array => [
                    'id' => $holiday->id,
                    'name' => $holiday->name,
                    'date' => $holiday->date->toDateString(),
                    'location_ids' => $holiday->locations->pluck('id')->sort()->values(),
                    'locations' => $holiday->locations->pluck('name')->sort()->values(),
                    'date_label' => $holiday->date->format('j F'),
                    'weekday' => $holiday->date->format('l'),
                    'past' => $holiday->date->lessThan($today),
                ])
                ->values(),
            // Inactive sites too, so a holiday already set for one can still
            // be edited without the form quietly making it company-wide.
            'locations' => Location::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Location $location): array => ['value' => $location->id, 'label' => $location->name])
                ->values(),
            // Every year that has one, and this one and next whether or not,
            // so the switcher can always reach the year being planned.
            'years' => PublicHoliday::query()
                ->pluck('date')
                ->map(fn (Carbon|string $date): int => Carbon::parse($date)->year)
                ->merge([Carbon::now()->year, Carbon::now()->year + 1, $year])
                ->unique()
                ->sort()
                ->values(),
        ]);
    }

    public function store(PublicHolidayRequest $request): RedirectResponse
    {
        $holiday = DB::transaction(function () use ($request): PublicHoliday {
            $holiday = PublicHoliday::query()->create($request->safe()->only(['name', 'date']));
            $holiday->locations()->sync($request->locationIds());

            return $holiday;
        });

        return $this->done("{$holiday->name} has been added.", $holiday);
    }

    public function update(PublicHolidayRequest $request, PublicHoliday $holiday): RedirectResponse
    {
        DB::transaction(function () use ($request, $holiday): void {
            $holiday->update($request->safe()->only(['name', 'date']));
            $holiday->locations()->sync($request->locationIds());
        });

        return $this->done("{$holiday->name} has been updated.", $holiday);
    }

    public function destroy(PublicHoliday $holiday): RedirectResponse
    {
        $holiday->delete();

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$holiday->name} has been taken off the calendar.",
        ]);
    }

    /**
     * Back to the year the holiday is in, so adding one for next year does
     * not leave it out of sight.
     */
    protected function done(string $message, PublicHoliday $holiday): RedirectResponse
    {
        return redirect()
            ->route('admin.holidays.index', ['year' => $holiday->date->year])
            ->with('toast', ['type' => 'success', 'message' => $message]);
    }
}
