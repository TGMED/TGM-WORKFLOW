<?php

namespace App\Services;

use App\Models\LeaveRequest;
use App\Models\OutOfOfficeRequest;
use App\Models\PublicHoliday;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What is coming up, for the rail that sits beside every page.
 *
 * Drawn from what the app already knows rather than from a diary somebody has
 * to keep: birthdays and work anniversaries off the staff records, approved
 * leave and agreed days out of the office off the requests, and the public
 * holidays the people team has set. Nothing here is entered twice.
 */
class UpcomingEvents
{
    public function __construct(protected Celebrations $celebrations) {}

    /**
     * Everything falling in the next stretch of days, soonest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forUser(User $user, int $days = 14): array
    {
        $today = Carbon::now()->startOfDay();
        $until = $today->copy()->addDays($days);

        $events = [
            ...$this->holidayEvents($today, $until),
            ...$this->celebrationEvents($today, $days),
            ...$this->leaveEvents($user, $today, $until),
            ...$this->outOfOfficeEvents($user, $today, $until),
        ];

        usort($events, fn (array $a, array $b): int => [$a['date'], $a['label']] <=> [$b['date'], $b['label']]);

        return $events;
    }

    /**
     * Days the whole company is off.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function holidayEvents(Carbon $today, Carbon $until): array
    {
        $events = [];

        foreach (PublicHoliday::between($today, $until) as $date => $name) {
            $events[] = $this->event(Carbon::parse($date), 'holiday', $name, 'Public holiday');
        }

        return $events;
    }

    /**
     * Birthdays and work anniversaries over the window.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function celebrationEvents(Carbon $today, int $days): array
    {
        $events = [];

        for ($offset = 0; $offset <= $days; $offset++) {
            $date = $today->copy()->addDays($offset);

            foreach ($this->celebrations->birthdaysOn($date) as $person) {
                $events[] = $this->event($date, 'birthday', $person->name, 'Birthday');
            }

            foreach ($this->celebrations->anniversariesOn($date) as $person) {
                $years = $this->celebrations->yearsServedOn($person, $date);

                $events[] = $this->event(
                    $date,
                    'anniversary',
                    $person->name,
                    $years === 1 ? '1 year with us' : "{$years} years with us",
                );
            }
        }

        return $events;
    }

    /**
     * Leave starting in the window. Only the start: a rail listing every day
     * of a fortnight's holiday would be a wall of one person's name.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function leaveEvents(User $user, Carbon $today, Carbon $until): array
    {
        return LeaveRequest::query()
            ->with(['user:id,name,department_id', 'leaveType:id,name'])
            ->approved()
            ->whereBetween('start_date', [$today->toDateString(), $until->toDateString()])
            ->get()
            ->map(fn (LeaveRequest $leave): array => $this->event(
                $leave->start_date,
                'leave',
                $leave->user_id === $user->id ? 'You' : $leave->user->name,
                'Away · '.$leave->leaveType->name,
            ))
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function outOfOfficeEvents(User $user, Carbon $today, Carbon $until): array
    {
        return OutOfOfficeRequest::query()
            ->with('user:id,name')
            ->approved()
            ->whereBetween('start_date', [$today->toDateString(), $until->toDateString()])
            ->get()
            ->map(fn (OutOfOfficeRequest $away): array => $this->event(
                $away->start_date,
                'out_of_office',
                $away->user_id === $user->id ? 'You' : $away->user->name,
                $away->kind->label(),
            ))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function event(Carbon $date, string $kind, string $who, string $label): array
    {
        $today = Carbon::now()->startOfDay();
        $days = (int) $today->diffInDays($date, false);

        return [
            'date' => $date->toDateString(),
            'day_label' => $date->format('D j M'),
            'when' => match (true) {
                $days === 0 => 'Today',
                $days === 1 => 'Tomorrow',
                $days < 7 => $date->format('l'),
                default => $date->format('j M'),
            },
            'kind' => $kind,
            'who' => $who,
            'label' => $label,
        ];
    }
}
