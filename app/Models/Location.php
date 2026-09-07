<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A place staff clock in at. Each site owns its own geofence and working day,
 * so a branch in another city is judged against its own opening time.
 *
 * @property int $id
 * @property string $name
 * @property string $address
 * @property string|null $city
 * @property float|null $latitude
 * @property float|null $longitude
 * @property int $radius_meters
 * @property int $max_accuracy_meters
 * @property string $work_starts_at
 * @property string $work_ends_at
 * @property int $grace_minutes
 * @property int $break_minutes
 * @property array<int, int>|null $workdays
 * @property string $timezone
 * @property bool $is_active
 * @property bool $accepts_signups
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'name',
    'address',
    'city',
    'latitude',
    'longitude',
    'radius_meters',
    'max_accuracy_meters',
    'work_starts_at',
    'work_ends_at',
    'grace_minutes',
    'break_minutes',
    'workdays',
    'timezone',
    'is_active',
    'accepts_signups',
])]
class Location extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * Mirrors the column defaults so a new location is fully populated in
     * memory, not only in the database.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'radius_meters' => 150,
        'max_accuracy_meters' => 200,
        'work_starts_at' => '09:00:00',
        'work_ends_at' => '17:00:00',
        'grace_minutes' => 10,
        'break_minutes' => 60,
        'workdays' => '[1,2,3,4,5]',
        'timezone' => 'Africa/Lagos',
        'is_active' => true,
        'accepts_signups' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'integer',
            'max_accuracy_meters' => 'integer',
            'grace_minutes' => 'integer',
            'break_minutes' => 'integer',
            'workdays' => 'array',
            'is_active' => 'boolean',
            'accepts_signups' => 'boolean',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<Attendance, $this>
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function hasCoordinates(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * ISO-8601 weekday numbers (1 = Monday) that count as working days.
     *
     * @return array<int, int>
     */
    /**
     * Whether staff at this site take a break at all.
     */
    public function allowsBreaks(): bool
    {
        return $this->break_minutes > 0;
    }

    /**
     * @return array<int, int>
     */
    public function workdayNumbers(): array
    {
        return $this->workdays ?: [1, 2, 3, 4, 5];
    }

    public function isWorkday(CarbonInterface $date): bool
    {
        return in_array($date->isoWeekday(), $this->workdayNumbers(), true);
    }

    /**
     * The moment work starts on the given day, in this site's timezone.
     */
    public function startOfWorkFor(CarbonInterface $date): CarbonInterface
    {
        [$hour, $minute] = array_map('intval', explode(':', $this->work_starts_at));

        return $date->copy()
            ->setTimezone($this->timezone)
            ->setTime($hour, $minute)
            ->startOfMinute();
    }

    /**
     * The latest arrival that still counts as on time.
     */
    public function latenessCutoffFor(CarbonInterface $date): CarbonInterface
    {
        return $this->startOfWorkFor($date)->addMinutes($this->grace_minutes);
    }

    /**
     * @param  Builder<Location>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Location>  $query
     */
    public function scopeOpenToSignups(Builder $query): void
    {
        $query->where('is_active', true)->where('accepts_signups', true);
    }
}
