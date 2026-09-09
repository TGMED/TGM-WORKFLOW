<?php

namespace App\Services\Metrics;

use App\Enums\AttendanceStatus;
use App\Enums\RequestStatus;
use App\Models\Attendance;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How a group of people are doing: a department for its head, a team for its
 * lead. The same numbers either way, over a different set of ids, so a head
 * and a lead read the same page and there is one set of queries to keep right.
 *
 * Everything is grouped rather than asked per person: a department of forty
 * would otherwise be forty round trips for one panel.
 */
class GroupMetrics
{
    /**
     * @param  array<int, int>  $userIds
     * @return array<string, mixed>
     */
    public function for(array $userIds, string $label, string $kind): array
    {
        if ($userIds === []) {
            return [
                'label' => $label,
                'kind' => $kind,
                'headcount' => 0,
                'headline' => $this->emptyHeadline(),
                'trend' => [],
                'members' => [],
            ];
        }

        $members = User::query()
            ->whereKey($userIds)
            ->with('location:id,name,timezone', 'team:id,name')
            ->orderBy('name')
            ->get();

        $today = Carbon::now()->startOfDay();
        $monthStart = $today->copy()->startOfMonth();

        $attendance = $this->attendanceByUser($userIds, $monthStart, $today);
        $todays = $this->todayByUser($members);
        $leave = $this->leaveDaysByUser($userIds, $today->year);
        $open = $this->openRequestsByUser($userIds);

        $rows = $members->map(fn (User $member): array => $this->member(
            $member,
            $attendance->get($member->id) ?? AttendanceTally::none($member->id),
            $todays->get($member->id),
            (int) ($leave[$member->id] ?? 0),
            (int) ($open[$member->id] ?? 0),
        ));

        return [
            'label' => $label,
            'kind' => $kind,
            'headcount' => $members->count(),
            'headline' => $this->headline($rows, $todays, $userIds, $today),
            'trend' => $this->trend($userIds, $today),
            'members' => $rows->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyHeadline(): array
    {
        return [
            'clocked_in_today' => 0,
            'late_today' => 0,
            'on_leave_today' => 0,
            'open_requests' => 0,
            'punctuality' => null,
            'late_minutes_this_month' => 0,
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  Collection<int, Attendance>  $todays
     * @param  array<int, int>  $userIds
     * @return array<string, mixed>
     */
    protected function headline(Collection $rows, Collection $todays, array $userIds, Carbon $today): array
    {
        $present = (int) $rows->sum('days_present');
        $late = (int) $rows->sum('days_late');

        return [
            'clocked_in_today' => $todays->filter(
                fn (Attendance $record): bool => $record->clocked_in_at !== null,
            )->count(),
            'late_today' => $todays->filter(
                fn (Attendance $record): bool => $record->countsAsLate(),
            )->count(),
            'on_leave_today' => LeaveRequest::query()
                ->whereIn('user_id', $userIds)
                ->approved()
                ->overlapping($today, $today)
                ->distinct()
                ->count('user_id'),
            'open_requests' => (int) $rows->sum('open_requests'),
            // Null rather than a hundred per cent when nobody has worked a day
            // this month: a figure with nothing behind it reads as a fact.
            'punctuality' => $present > 0
                ? (int) round((($present - $late) / $present) * 100)
                : null,
            'late_minutes_this_month' => (int) $rows->sum('late_minutes'),
        ];
    }

    /**
     * This month's attendance per person, in one grouped query.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, AttendanceTally>
     */
    protected function attendanceByUser(array $userIds, Carbon $from, Carbon $to): Collection
    {
        return AttendanceTally::over(
            Attendance::query()->whereIn('user_id', $userIds),
            $from->toDateString(),
            $to->toDateString(),
        );
    }

    /**
     * Today's row per person. Each site keeps its own working day, so "today"
     * is asked of each one in its own timezone rather than of the server.
     *
     * @param  Collection<int, User>  $members
     * @return Collection<int, Attendance>
     */
    protected function todayByUser(Collection $members): Collection
    {
        $byLocation = $members->groupBy(fn (User $member): string => (string) $member->location_id);

        $query = Attendance::query();
        $any = false;

        foreach ($byLocation as $locationId => $group) {
            if ($locationId === '') {
                continue;
            }

            /** @var User $first */
            $first = $group->first();
            $timezone = $first->location === null
                ? (string) config('app.timezone')
                : $first->location->timezone;
            $localDate = Carbon::now()->setTimezone($timezone)->toDateString();

            $query->orWhere(fn ($q) => $q
                ->whereIn('user_id', $group->pluck('id'))
                ->where('work_date', $localDate));

            $any = true;
        }

        if (! $any) {
            return collect();
        }

        return $query->get()->keyBy('user_id');
    }

    /**
     * Leave days already committed this year, per person.
     *
     * @param  array<int, int>  $userIds
     * @return Collection<int, mixed>
     */
    protected function leaveDaysByUser(array $userIds, int $year): Collection
    {
        return LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->committed()
            ->inYear($year)
            ->selectRaw('user_id, sum(days) as days')
            ->groupBy('user_id')
            ->pluck('days', 'user_id');
    }

    /**
     * Requests still open per person, both modules together.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, int>
     */
    protected function openRequestsByUser(array $userIds): array
    {
        $leave = LeaveRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('status', RequestStatus::Pending->value)
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $lateness = LatenessRequest::query()
            ->whereIn('user_id', $userIds)
            ->where('status', RequestStatus::Pending->value)
            ->selectRaw('user_id, count(*) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id');

        $totals = [];

        foreach ($userIds as $id) {
            $totals[$id] = (int) ($leave[$id] ?? 0) + (int) ($lateness[$id] ?? 0);
        }

        return $totals;
    }

    /**
     * @return array<string, mixed>
     */
    protected function member(
        User $member,
        AttendanceTally $month,
        ?Attendance $today,
        int $leaveDays,
        int $openRequests,
    ): array {
        return [
            'id' => $member->id,
            'name' => $member->name,
            'position' => $member->position,
            'team' => $member->team?->name,
            'location' => $member->location?->name,
            'days_present' => $month->daysPresent,
            'days_late' => $month->daysLate,
            'late_minutes' => $month->lateMinutes,
            'worked_hours' => (int) round($month->workedMinutes / 60),
            'leave_days_taken' => $leaveDays,
            'open_requests' => $openRequests,
            'last_seen' => $month->lastSeen === null
                ? null
                : Carbon::parse($month->lastSeen)->toDateString(),
            'punctuality' => $month->punctuality(),
            'clocked_in_today' => $today?->clocked_in_at !== null,
            'late_today' => $today?->countsAsLate() ?? false,
        ];
    }

    /**
     * Fourteen days of turnout for the group, on time against late. One
     * grouped query rather than one per day.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, mixed>>
     */
    protected function trend(array $userIds, Carbon $today): array
    {
        $start = $today->copy()->subDays(13);

        $rows = Attendance::query()
            ->whereIn('user_id', $userIds)
            ->whereBetween('work_date', [$start->toDateString(), $today->toDateString()])
            ->selectRaw(
                'work_date, count(*) as total, '.
                'sum(case when status = ? and excused_at is null then 1 else 0 end) as late',
                [AttendanceStatus::Late->value],
            )
            ->groupBy('work_date')
            ->get()
            ->keyBy(fn (Attendance $row): string => Carbon::parse($row->work_date)->toDateString());

        $days = [];

        for ($cursor = $start->copy(); $cursor->lessThanOrEqualTo($today); $cursor = $cursor->addDay()) {
            $row = $rows->get($cursor->toDateString());
            $total = (int) ($row->total ?? 0);
            $late = (int) ($row->late ?? 0);

            $days[] = [
                'date' => $cursor->toDateString(),
                'label' => $cursor->format('D j'),
                'present' => $total,
                'late' => $late,
                'on_time' => $total - $late,
            ];
        }

        return $days;
    }
}
