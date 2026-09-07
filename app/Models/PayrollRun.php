<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * One month's payroll.
 *
 * @property int $id
 * @property int $year
 * @property int $month
 * @property PayrollRunStatus $status
 * @property int|null $created_by_id
 * @property int|null $finalised_by_id
 * @property Carbon|null $finalised_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $createdBy
 * @property-read User|null $finalisedBy
 */
#[Fillable(['year', 'month', 'status', 'created_by_id', 'finalised_by_id', 'finalised_at'])]
class PayrollRun extends Model implements AuditableContract
{
    use Auditable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'status' => PayrollRunStatus::class,
            'finalised_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Payslip, $this>
     */
    public function payslips(): HasMany
    {
        return $this->hasMany(Payslip::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function finalisedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalised_by_id');
    }

    public function isDraft(): bool
    {
        return $this->status->isDraft();
    }

    /**
     * The month it covers, as anyone would say it.
     */
    public function periodLabel(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('F Y');
    }

    /**
     * Newest month first, whatever order the rows were created in: a run for
     * a back month can be built after a later one.
     *
     * @param  Builder<PayrollRun>  $query
     */
    public function scopeInPeriodOrder(Builder $query): void
    {
        $query->orderByDesc('year')->orderByDesc('month');
    }
}
