<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $location_id
 * @property Carbon $work_date
 * @property Carbon|null $clocked_in_at
 * @property float|null $clock_in_latitude
 * @property float|null $clock_in_longitude
 * @property int|null $clock_in_accuracy
 * @property int|null $clock_in_distance
 * @property Carbon|null $clocked_out_at
 * @property float|null $clock_out_latitude
 * @property float|null $clock_out_longitude
 * @property int|null $clock_out_accuracy
 * @property int|null $clock_out_distance
 * @property Carbon|null $break_started_at
 * @property Carbon|null $break_ended_at
 * @property int|null $break_minutes
 * @property AttendanceStatus $status
 * @property int $late_minutes
 * @property int|null $worked_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read Location|null $location
 */
#[Fillable([
    'user_id',
    'location_id',
    'work_date',
    'clocked_in_at',
    'clock_in_latitude',
    'clock_in_longitude',
    'clock_in_accuracy',
    'clock_in_distance',
    'clocked_out_at',
    'clock_out_latitude',
    'clock_out_longitude',
    'clock_out_accuracy',
    'clock_out_distance',
    'break_started_at',
    'break_ended_at',
    'break_minutes',
    'status',
    'late_minutes',
    'worked_minutes',
])]
class Attendance extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'clocked_in_at' => 'datetime',
            'clocked_out_at' => 'datetime',
            'break_started_at' => 'datetime',
            'break_ended_at' => 'datetime',
            'clock_in_latitude' => 'float',
            'clock_in_longitude' => 'float',
            'clock_out_latitude' => 'float',
            'clock_out_longitude' => 'float',
            'status' => AttendanceStatus::class,
        ];
    }

    /**
     * Always persist the work date as a bare `Y-m-d` string so the
     * (user_id, work_date) unique key and lookups line up on every driver.
     *
     * @return Attribute<Carbon, string>
     */
    protected function workDate(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): Carbon => Carbon::parse($value)->startOfDay(),
            set: fn (Carbon|string $value): string => Carbon::parse($value)->toDateString(),
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The site this day was measured against.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @return HasMany<ClockAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(ClockAttempt::class);
    }

    public function isOpen(): bool
    {
        return $this->clocked_in_at !== null && $this->clocked_out_at === null;
    }

    /**
     * A break that has started and not yet been ended.
     */
    public function isOnBreak(): bool
    {
        return $this->break_started_at !== null && $this->break_ended_at === null;
    }

    public function hasTakenBreak(): bool
    {
        return $this->break_ended_at !== null;
    }

    /**
     * Minutes a running break has been going for, or the settled length once
     * it has ended.
     */
    public function breakMinutesSoFar(): int
    {
        if ($this->break_started_at === null) {
            return 0;
        }

        return $this->break_minutes
            ?? max(0, (int) $this->break_started_at->diffInMinutes(Carbon::now()));
    }

    /**
     * How far past the site's limit this break ran. Overruns are recorded and
     * shown rather than blocked.
     */
    public function breakOverrunMinutes(?int $limit): int
    {
        if ($limit === null || $limit <= 0) {
            return 0;
        }

        return max(0, $this->breakMinutesSoFar() - $limit);
    }

    /**
     * @param  Builder<Attendance>  $query
     */
    public function scopeLate(Builder $query): void
    {
        $query->where('status', AttendanceStatus::Late->value);
    }

    /**
     * @param  Builder<Attendance>  $query
     */
    public function scopeBetween(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->whereBetween('work_date', [$from->toDateString(), $to->toDateString()]);
    }
}
