<?php

namespace App\Models;

use Database\Factories\PublicHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A day off, either for the whole company or for some of its sites.
 *
 * With no sites it is a day every office is off; with some it is a state or
 * local holiday only those offices keep. Nobody it applies to is expected in,
 * whatever their site's week says, so it comes off the days a person is
 * expected on the attendance report and is never deducted from leave or
 * counted as a day working elsewhere.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Location> $locations
 */
#[Fillable(['name', 'date'])]
class PublicHoliday extends Model implements AuditableContract
{
    use Auditable;

    /** @use HasFactory<PublicHolidayFactory> */
    use HasFactory;

    /**
     * Stored as bare `Y-m-d`, matching leave requests, so the two compare the
     * same way on every driver.
     *
     * @return Attribute<Carbon, string>
     */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): Carbon => Carbon::parse($value)->startOfDay(),
            set: fn (Carbon|string $value): string => Carbon::parse($value)->toDateString(),
        );
    }

    /**
     * The sites that keep this holiday, or none when every site does.
     *
     * @return BelongsToMany<Location, $this>
     */
    public function locations(): BelongsToMany
    {
        return $this->belongsToMany(Location::class);
    }

    /**
     * The holidays somebody at any of these sites is off for: the
     * company-wide ones and those the sites keep. With no site, only the
     * company-wide ones.
     *
     * @param  Builder<self>  $query
     * @param  int|list<int>|null  $locationIds
     */
    public function scopeObservedAt(Builder $query, int|array|null $locationIds): void
    {
        $locationIds = array_values(array_filter((array) $locationIds, fn (?int $id): bool => $id !== null));

        $query->where(function (Builder $query) use ($locationIds): void {
            $query->whereDoesntHave('locations');

            if ($locationIds !== []) {
                $query->orWhereHas('locations', fn (Builder $query) => $query->whereKey($locationIds));
            }
        });
    }

    /**
     * The holidays kept at a site in a range, as `Y-m-d` => name, soonest
     * first. Keyed by date so a caller can ask of any day whether it is one.
     *
     * @return array<string, string>
     */
    public static function between(Carbon $from, Carbon $to, ?int $locationId): array
    {
        return self::query()
            ->observedAt($locationId)
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('date')
            ->pluck('name', 'date')
            ->mapWithKeys(fn (string $name, string $date): array => [Carbon::parse($date)->toDateString() => $name])
            ->all();
    }

    /**
     * Just the dates, for counting working days.
     *
     * @return list<string>
     */
    public static function datesBetween(Carbon $from, Carbon $to, ?int $locationId): array
    {
        return array_keys(self::between($from, $to, $locationId));
    }

    /**
     * The holiday falling on a day at a site, if there is one.
     */
    public static function nameOn(Carbon $day, ?int $locationId): ?string
    {
        return self::between($day, $day, $locationId)[$day->toDateString()] ?? null;
    }
}
