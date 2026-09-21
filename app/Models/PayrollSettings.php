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
 * The payroll rules. Audited: a rate that changes between two months is the
 * first thing anyone asks about when a net figure moves, and the trail answers
 * it without a conversation.
 *
 * Normally one row, the company's, with a null `user_id`. A row carrying a
 * `user_id` is that person's own set, and it replaces the company's outright
 * rather than being merged with it: see `forUser()`.
 *
 * @property int $id
 * @property int|null $user_id
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
 * @property-read User|null $user
 */
#[Fillable([
    'user_id',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The company row, created on the spot if the table is somehow empty so a
     * payroll run can never fail for want of a settings record.
     */
    public static function current(): self
    {
        return self::query()
            ->whereNull('user_id')
            ->firstOr(fn (): self => self::query()->create([
                'tax_bands' => [['up_to' => null, 'rate' => 0]],
            ]));
    }

    /**
     * The rates this person is actually paid under: their own set where they
     * have one, the company's where they do not.
     *
     * All or nothing on purpose. A personal row is not merged field by field
     * with the company's, so every figure on somebody's payslip comes from one
     * row and can be pointed at.
     */
    public static function forUser(User $user): self
    {
        return self::query()->where('user_id', $user->id)->first() ?? self::current();
    }

    /**
     * Whether these are somebody's own rates rather than the company's.
     */
    public function isPersonal(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * The company rates copied onto a person, as the starting point for their
     * own set. Copied rather than referenced: from here the two drift, which
     * is what having a personal set means.
     */
    public static function copyToUser(User $user): self
    {
        $company = self::current();

        return self::query()->updateOrCreate(
            ['user_id' => $user->id],
            collect($company->attributesToArray())
                ->only([
                    'currency',
                    'basic_percent',
                    'housing_percent',
                    'transport_percent',
                    'pension_employee_percent',
                    'pension_employer_percent',
                    'nhf_percent',
                    'rent_relief_percent',
                    'rent_relief_cap',
                ])
                ->put('tax_bands', $company->tax_bands)
                ->all(),
        );
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
