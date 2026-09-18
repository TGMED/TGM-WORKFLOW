<?php

namespace App\Services;

use App\Models\RequestSettings;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When a lateness request may still be filed.
 *
 * The deadline sits a configurable stretch before the start of work at the
 * person's own site, which makes this a warning rather than an account of the
 * morning: somebody who knows at seven that they will not make nine says so
 * while their team can still plan around it. Miss the deadline and the day
 * stands as unexplained lateness for the attendance report to pick up.
 */
class LatenessWindow
{
    /**
     * The moment filing closes for that person on that day, or null where
     * their site's hours are unknown. A person with no location has no
     * resumption time to count back from, and refusing them on a deadline
     * nobody can name would be worse than letting the request through.
     */
    public function closesAt(User $user, CarbonInterface $date): ?CarbonInterface
    {
        $location = $user->loadMissing('location')->location;

        if ($location === null) {
            return null;
        }

        return $location->startOfWorkFor($date)
            ->subMinutes(RequestSettings::latenessCutoffMinutes());
    }

    /**
     * Whether filing is still open for that person on that day.
     */
    public function isOpen(User $user, CarbonInterface $date, ?CarbonInterface $now = null): bool
    {
        $closes = $this->closesAt($user, $date);

        if ($closes === null) {
            return true;
        }

        return ($now ?? Carbon::now())->lessThan($closes);
    }
}
