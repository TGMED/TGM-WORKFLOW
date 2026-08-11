<?php

namespace App\Services;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Storage;

/**
 * Whose birthday and whose work anniversary is coming up.
 *
 * Both are worked out in PHP rather than SQL: pulling the month and day out
 * of a date is spelled differently on every database, and the set being read
 * is one row per active employee.
 */
class Celebrations
{
    /**
     * How far ahead the dashboard looks, in days. Today counts as day zero.
     */
    public const WINDOW = 30;

    /**
     * @return array{birthdays: array<int, array<string, mixed>>, anniversaries: array<int, array<string, mixed>>}
     */
    public function upcoming(CarbonInterface $today, int $days = self::WINDOW): array
    {
        $start = $today->copy()->startOfDay();

        $staff = User::query()
            ->active()
            ->clocksIn()
            ->with('profile')
            ->get(['id', 'name', 'hired_at', 'department']);

        $horizon = $start->copy()->addDays($days);

        $birthdays = [];
        $anniversaries = [];

        foreach ($staff as $person) {
            $birthday = $person->profile?->date_of_birth;

            if ($birthday !== null) {
                $next = $this->nextOccurrence($birthday, $start);

                if ($next->lessThanOrEqualTo($horizon)) {
                    $birthdays[] = $this->entry($person, $next, $start);
                }
            }

            if ($person->hired_at !== null) {
                $next = $this->nextOccurrence($person->hired_at, $start);
                $years = $next->year - $person->hired_at->year;

                // Nobody marks the day they started on the day they started,
                // so a first anniversary is the earliest one worth showing.
                if ($years >= 1 && $next->lessThanOrEqualTo($horizon)) {
                    $anniversaries[] = $this->entry($person, $next, $start) + ['years' => $years];
                }
            }
        }

        return [
            'birthdays' => $this->sorted($birthdays),
            'anniversaries' => $this->sorted($anniversaries),
        ];
    }

    /**
     * The next time this day of the year comes round, counting today. A 29
     * February date lands on 1 March in the years that have no 29th.
     */
    protected function nextOccurrence(CarbonInterface $date, CarbonInterface $from): CarbonInterface
    {
        $occurrence = $date->copy()->startOfDay()->setYear($from->year);

        return $occurrence->lessThan($from)
            ? $date->copy()->startOfDay()->setYear($from->year + 1)
            : $occurrence;
    }

    /**
     * @return array<string, mixed>
     */
    protected function entry(User $person, CarbonInterface $occurrence, CarbonInterface $today): array
    {
        $away = (int) $today->diffInDays($occurrence, false);

        return [
            'id' => $person->id,
            'name' => $person->name,
            'department' => $person->department,
            'initials' => $person->initials,
            'avatar_url' => $person->profile?->avatar_path === null
                ? null
                : Storage::disk('public')->url($person->profile->avatar_path),
            'date' => $occurrence->toDateString(),
            'days_away' => $away,
            'is_today' => $away === 0,
            'when' => match (true) {
                $away === 0 => 'Today',
                $away === 1 => 'Tomorrow',
                default => $occurrence->format('D j M'),
            },
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, array<string, mixed>>
     */
    protected function sorted(array $entries): array
    {
        usort($entries, fn (array $a, array $b): int => [$a['days_away'], $a['name']] <=> [$b['days_away'], $b['name']]);

        return $entries;
    }
}
