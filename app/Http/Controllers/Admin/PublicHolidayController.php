<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicHolidayRequest;
use App\Models\PublicHoliday;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The days the whole company is off. Kept with the sites, since a holiday is
 * a day taken out of every site's week at once.
 */
class PublicHolidayController extends Controller
{
    public function index(Request $request): Response
    {
        $year = (int) $request->integer('year', Carbon::now()->year);
        $today = Carbon::now()->startOfDay();

        $holidays = PublicHoliday::query()
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
                    'date_label' => $holiday->date->format('j F'),
                    'weekday' => $holiday->date->format('l'),
                    'past' => $holiday->date->lessThan($today),
                ])
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
        $holiday = PublicHoliday::query()->create($request->validated());

        return $this->done("{$holiday->name} has been added.", $holiday);
    }

    public function update(PublicHolidayRequest $request, PublicHoliday $holiday): RedirectResponse
    {
        $holiday->update($request->validated());

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
