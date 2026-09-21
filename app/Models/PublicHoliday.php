<?php

namespace App\Models;

use Database\Factories\PublicHolidayFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * A day the whole company is off.
 *
 * Nobody is expected in on one, whatever their site's week says, so it comes
 * off the days a person is expected on the attendance report and is never
 * deducted from leave or counted as a day working elsewhere.
 *
 * @property int $id
 * @property string $name
 * @property Carbon $date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
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
     * The holidays in a range, as `Y-m-d` => name, soonest first. Keyed by
     * date so a caller can ask of any day whether it is one.
     *
     * @return array<string, string>
     */
    public static function between(Carbon $from, Carbon $to): array
    {
        return self::query()
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
    public static function datesBetween(Carbon $from, Carbon $to): array
    {
        return array_keys(self::between($from, $to));
    }

    /**
     * The holiday falling on a day, if there is one.
     */
    public static function nameOn(Carbon $day): ?string
    {
        return self::between($day, $day)[$day->toDateString()] ?? null;
    }
}
