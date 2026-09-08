<?php

namespace App\Services;

use App\Models\BirthdayGreeting;
use App\Models\User;
use App\Models\WorkAnniversaryGreeting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Who is celebrating today.
 *
 * Birthdays are read off the HR record the employee keeps themselves, so
 * anyone who has not filled theirs in simply does not appear. Anniversaries
 * are read off the hire date on the staff record instead, which HR keeps.
 */
class Celebrations
{
    /**
     * People whose birthday falls on the date given and who have not been
     * greeted this year yet.
     *
     * The day and month are compared rather than the whole date, which no
     * driver-portable date function does the same way, so the shortlist is
     * pulled back and settled in PHP. The staff list is small enough that
     * this costs nothing worth optimising.
     *
     * @return Collection<int, User>
     */
    public function birthdaysOn(Carbon $date): Collection
    {
        $greeted = BirthdayGreeting::query()
            ->where('year', $date->year)
            ->pluck('user_id')
            ->all();

        return User::query()
            ->active()
            ->with('profile')
            ->whereHas('profile', fn ($query) => $query->whereNotNull('date_of_birth'))
            ->whereKeyNot($greeted)
            ->get()
            ->filter(function (User $user) use ($date): bool {
                $birthday = $user->profile?->date_of_birth;

                if ($birthday === null) {
                    return false;
                }

                // Someone born on 29 February is greeted on the 28th in a
                // year that has no 29th, rather than skipped for three years
                // in every four.
                if ($birthday->month === 2 && $birthday->day === 29 && ! $date->isLeapYear()) {
                    return $date->month === 2 && $date->day === 28;
                }

                return $birthday->month === $date->month && $birthday->day === $date->day;
            })
            ->values();
    }

    /**
     * People whose hire date falls on the day and month given, who have been
     * here at least a full year, and who have not been written to this year
     * yet.
     *
     * Somebody hired today has no anniversary to mark, and their first one
     * comes round next year. The day and month are settled in PHP for the
     * same reason birthdays are.
     *
     * @return Collection<int, User>
     */
    public function anniversariesOn(Carbon $date): Collection
    {
        $greeted = WorkAnniversaryGreeting::query()
            ->where('year', $date->year)
            ->pluck('user_id')
            ->all();

        return User::query()
            ->active()
            ->with('profile')
            ->whereNotNull('hired_at')
            ->whereKeyNot($greeted)
            ->get()
            ->filter(function (User $user) use ($date): bool {
                $hired = $user->hired_at;

                if ($hired === null || $hired->year >= $date->year) {
                    return false;
                }

                // Somebody hired on 29 February is written to on the 28th in
                // a year without a 29th, rather than skipped three years in
                // every four.
                if ($hired->month === 2 && $hired->day === 29 && ! $date->isLeapYear()) {
                    return $date->month === 2 && $date->day === 28;
                }

                return $hired->month === $date->month && $hired->day === $date->day;
            })
            ->values();
    }

    /**
     * Whole years served as of the date given. The caller has already settled
     * that this is the person's anniversary, so the year difference is the
     * count, without the date arithmetic having to agree about 29 February.
     */
    public function yearsServedOn(User $user, Carbon $date): int
    {
        return $user->hired_at === null ? 0 : $date->year - $user->hired_at->year;
    }
}
