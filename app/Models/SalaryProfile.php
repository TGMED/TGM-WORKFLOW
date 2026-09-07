<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * What one person is paid. Audited, because a change to somebody's salary is
 * exactly the kind of thing that has to be answerable for later.
 *
 * @property int $id
 * @property int $user_id
 * @property float $annual_gross
 * @property bool $pension_applies
 * @property bool $nhf_applies
 * @property Carbon|null $effective_from
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 */
#[Fillable(['user_id', 'annual_gross', 'pension_applies', 'nhf_applies', 'effective_from'])]
class SalaryProfile extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'annual_gross' => 'float',
            'pension_applies' => 'boolean',
            'nhf_applies' => 'boolean',
            'effective_from' => 'date',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function monthlyGross(): float
    {
        return round($this->annual_gross / 12, 2);
    }
}
