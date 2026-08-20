<?php

namespace App\Services;

use App\Models\BirthdayGreeting;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Who is celebrating today.
 *
 * Birthdays are read off the HR record the employee keeps themselves, so
 * anyone who has not filled theirs in simply does not appear.
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
}
