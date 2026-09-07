<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Auditable;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * The one row of payroll rules. Audited: a rate that changes between two
 * months is the first thing anyone asks about when a net figure moves, and
 * the trail answers it without a conversation.
 *
 * @property int $id
 * @property string $currency
 * @property float $basic_percent
 * @property float $housing_percent
 * @property float $transport_percent
 * @property float $pension_employee_percent
 * @property float $pension_employer_percent
 * @property float $nhf_percent
 * @property float $rent_relief_percent
 * @property float $rent_relief_cap
 * @property array<int, array<string, mixed>> $tax_bands
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'currency',
    'basic_percent',
    'housing_percent',
    'transport_percent',
    'pension_employee_percent',
    'pension_employer_percent',
    'nhf_percent',
    'rent_relief_percent',
    'rent_relief_cap',
    'tax_bands',
])]
class PayrollSettings extends Model implements AuditableContract
{
    use Auditable;
    use SoftDeletes;

    protected $table = 'payroll_settings';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'basic_percent' => 'float',
            'housing_percent' => 'float',
            'transport_percent' => 'float',
            'pension_employee_percent' => 'float',
            'pension_employer_percent' => 'float',
            'nhf_percent' => 'float',
            'rent_relief_percent' => 'float',
            'rent_relief_cap' => 'float',
            'tax_bands' => 'array',
        ];
    }

    /**
     * The single row, created on the spot if the table is somehow empty so a
     * payroll run can never fail for want of a settings record.
     */
    public static function current(): self
    {
        return self::query()->firstOr(fn (): self => self::query()->create([
            'tax_bands' => [['up_to' => null, 'rate' => 0]],
        ]));
    }

    /**
     * The slice of gross that is not basic, housing or transport. Pension is
     * assessed on the first three, so what falls outside them matters.
     */
    public function otherPercent(): float
    {
        return round(
            100 - $this->basic_percent - $this->housing_percent - $this->transport_percent,
            2,
        );
    }

    /**
     * The bands in the order tax is charged, cheapest first, with the open
     * band last however the row was written.
     *
     * @return array<int, array{up_to: float|null, rate: float}>
     */
    public function orderedBands(): array
    {
        $bands = array_map(fn (array $band): array => [
            'up_to' => isset($band['up_to']) ? (float) $band['up_to'] : null,
            'rate' => (float) ($band['rate'] ?? 0),
        ], $this->tax_bands);

        usort($bands, function (array $a, array $b): int {
            // The open-ended band tops the table whatever else is there.
            if ($a['up_to'] === null) {
                return 1;
            }

            if ($b['up_to'] === null) {
                return -1;
            }

            return $a['up_to'] <=> $b['up_to'];
        });

        return $bands;
    }
}
