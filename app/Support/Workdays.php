<?php

namespace App\Support;

use Illuminate\Support\Carbon;

final class Workdays
{
    /**
     * Working days in a date range, inclusive of both ends. Weekends and other
     * non-working days come from the site's own schedule, so a Saturday shift
     * pattern is counted the way that site actually runs.
     *
     * @param  array<int, int>  $workdays  ISO day numbers, 1 (Mon) to 7 (Sun)
     */
    public static function countBetween(Carbon $start, Carbon $end, array $workdays): int
    {
        if ($end->lessThan($start)) {
            return 0;
        }

        $days = 0;

        for ($day = $start->copy()->startOfDay(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            if (in_array($day->isoWeekday(), $workdays, true)) {
                $days++;
            }
        }

        return $days;
    }
}
