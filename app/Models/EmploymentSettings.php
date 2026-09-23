<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The one row of company-wide employment rules. Audited, since a probation
 * that changes length changes when everybody on it is due.
 *
 * @property int $id
 * @property int $probation_months
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['probation_months'])]
class EmploymentSettings extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'employment_settings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'probation_months' => 'integer',
        ];
    }

    /**
     * The single row, made on first read from the length the config used to
     * carry, so nothing moves on the day this ships.
     */
    public static function current(): self
    {
        return self::query()->firstOr(fn (): self => self::query()->create([
            'probation_months' => (int) config('hr.probation_months', 6),
        ]));
    }

    public static function probationMonths(): int
    {
        return max(1, self::current()->probation_months);
    }
}
