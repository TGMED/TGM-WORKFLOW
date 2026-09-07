<?php

namespace App\Models;

use App\Enums\PayrollRunStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * One person's pay for one month, frozen as it was worked out.
 *
 * @property int $id
 * @property int $payroll_run_id
 * @property int $user_id
 * @property string $currency
 * @property int $year
 * @property int $month
 * @property array<int, array<string, mixed>> $earnings
 * @property array<int, array<string, mixed>> $deductions
 * @property float $gross_pay
 * @property float $total_earnings
 * @property float $total_deductions
 * @property float $net_pay
 * @property float $employer_pension
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read PayrollRun $run
 * @property-read User $user
 */
#[Fillable([
    'payroll_run_id',
    'user_id',
    'currency',
    'year',
    'month',
    'earnings',
    'deductions',
    'gross_pay',
    'total_earnings',
    'total_deductions',
    'net_pay',
    'employer_pension',
])]
class Payslip extends Model
{
    use SoftDeletes;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'month' => 'integer',
            'earnings' => 'array',
            'deductions' => 'array',
            'gross_pay' => 'float',
            'total_earnings' => 'float',
            'total_deductions' => 'float',
            'net_pay' => 'float',
            'employer_pension' => 'float',
        ];
    }

    /**
     * @return BelongsTo<PayrollRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function periodLabel(): string
    {
        return Carbon::create($this->year, $this->month, 1)->format('F Y');
    }

    /**
     * Payslips staff may see: a draft run is the finance team's working copy,
     * and nothing in it is shown until the run is signed off.
     *
     * @param  Builder<Payslip>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->whereHas(
            'run',
            fn (Builder $run) => $run->where('status', PayrollRunStatus::Finalised->value),
        );
    }

    /**
     * @param  Builder<Payslip>  $query
     */
    public function scopeInPeriodOrder(Builder $query): void
    {
        $query->orderByDesc('year')->orderByDesc('month');
    }
}
