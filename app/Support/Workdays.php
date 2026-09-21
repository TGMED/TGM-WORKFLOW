<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class Workdays
{
    /**
     * Working days in a date range, inclusive of both ends. Weekends and other
     * non-working days come from the site's own schedule, so a Saturday shift
     * pattern is counted the way that site actually runs. A public holiday is
     * never a working day, whatever the site's week says.
     *
     * @param  array<int, int>  $workdays  ISO day numbers, 1 (Mon) to 7 (Sun)
     * @param  array<int, string>  $holidays  `Y-m-d` dates the company is off
     */
    public static function countBetween(Carbon $start, Carbon $end, array $workdays, array $holidays = []): int
    {
        if ($end->lessThan($start)) {
            return 0;
        }

        $days = 0;

        for ($day = $start->copy()->startOfDay(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            if (in_array($day->isoWeekday(), $workdays, true) && ! in_array($day->toDateString(), $holidays, true)) {
                $days++;
            }
        }

        return $days;
    }
}
