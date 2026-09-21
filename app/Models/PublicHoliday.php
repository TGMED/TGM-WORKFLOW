<?php

namespace App\Models;

use Database\Factories\PublicHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A day off, either for the whole company or for one site.
 *
 * With no site it is a day every office is off; with one it is a state or
 * local holiday only that office keeps. Nobody it applies to is expected in,
 * whatever their site's week says, so it comes off the days a person is
 * expected on the attendance report and is never deducted from leave or
 * counted as a day working elsewhere.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $date
 * @property int|null $location_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Location|null $location
 */
#[Fillable(['name', 'date', 'location_id'])]
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'location_id' => 'integer',
        ];
    }

    /**
     * The site that keeps this holiday, or none when every site does.
     *
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * The holidays somebody at a site is off for: the company-wide ones and
     * that site's own. With no site, only the company-wide ones.
     *
     * @param  Builder<self>  $query
     */
    public function scopeObservedAt(Builder $query, ?int $locationId): void
    {
        $query->where(function (Builder $query) use ($locationId): void {
            $query->whereNull('location_id');

            if ($locationId !== null) {
                $query->orWhere('location_id', $locationId);
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
