<?php

namespace App\Services\Metrics;

use App\Enums\AttendanceStatus;
use App\Models\Attendance;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * One person's attendance over a window, already added up by the database.
 *
 * A named shape rather than a raw row: these totals are passed between panels,
 * and "an object with a days_present on it somewhere" is not something the next
 * reader can check. Building it here also keeps the one grouped query that
 * produces it in a single place.
 */
readonly class AttendanceTally
{
    public function __construct(
        public int $userId,
        public int $daysPresent,
        public int $daysLate,
        public int $lateMinutes,
        public int $workedMinutes,
        public ?string $lastSeen,
    ) {}

    /**
     * Somebody who has not worked a day in the window. A real tally of noughts
     * rather than a null, so every caller reads the same shape.
     */
    public static function none(int $userId): self
    {
        return new self($userId, 0, 0, 0, 0, null);
    }

    /**
     * Days on time as a percentage of days worked, or null when nobody has
     * worked a day yet: a figure with nothing behind it reads as a fact.
     */
    public function punctuality(): ?int
    {
        if ($this->daysPresent < 1) {
            return null;
        }

        return (int) round((($this->daysPresent - $this->daysLate) / $this->daysPresent) * 100);
    }

    /**
     * Everyone's totals for a window, in one grouped query, keyed by user.
     *
     * @param  Builder<Attendance>  $query  Already narrowed to the people wanted.
     * @return Collection<int, self>
     */
    public static function over(Builder $query, string $from, string $to): Collection
    {
        return $query
            ->whereBetween('work_date', [$from, $to])
            ->selectRaw(
                'user_id, count(*) as days_present, '.
                'sum(case when status = ? and excused_at is null then 1 else 0 end) as days_late, '.
                'sum(case when status = ? and excused_at is null then late_minutes else 0 end) as late_minutes, '.
                'sum(worked_minutes) as worked_minutes, max(work_date) as last_seen',
                [AttendanceStatus::Late->value, AttendanceStatus::Late->value],
            )
            ->groupBy('user_id')
            ->get()
            ->map(fn (Attendance $row): self => new self(
                userId: (int) $row->getAttribute('user_id'),
                daysPresent: (int) $row->getAttribute('days_present'),
                daysLate: (int) $row->getAttribute('days_late'),
                lateMinutes: (int) $row->getAttribute('late_minutes'),
                workedMinutes: (int) $row->getAttribute('worked_minutes'),
                lastSeen: $row->getAttribute('last_seen') === null
                    ? null
                    : (string) $row->getAttribute('last_seen'),
            ))
            ->keyBy(fn (self $tally): int => $tally->userId);
    }
}
