<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The one row of request-filing rules. Audited: a deadline that moves is the
 * answer to "why was my explanation refused when yesterday's was taken", and
 * the trail settles it without anyone having to remember.
 *
 * @property int $id
 * @property int $lateness_cutoff_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable(['lateness_cutoff_minutes'])]
class RequestSettings extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'request_settings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'lateness_cutoff_minutes' => 'integer',
        ];
    }

    /**
     * The single row, created on the spot if the table is somehow empty so a
     * request can never fail to be judged for want of a settings record.
     */
    public static function current(): self
    {
        return self::query()->firstOr(fn (): self => self::query()->create([]));
    }

    public static function latenessCutoffMinutes(): int
    {
        return max(0, self::current()->lateness_cutoff_minutes);
    }
}
