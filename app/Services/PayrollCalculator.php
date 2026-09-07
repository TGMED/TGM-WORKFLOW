<?php

namespace App\Services;

use App\Models\PayrollSettings;
use App\Models\SalaryProfile;

/**
 * Works one person's monthly pay out from their annual package.
 *
 * Everything is computed annually and divided by twelve at the end, because
 * that is how the tax is actually assessed: charging a twelfth of the package
 * through the bands each month gives a different — and wrong — answer for
 * anyone whose pay straddles a band.
 *
 * Every rate the arithmetic uses comes from PayrollSettings. There are no
 * numbers in this class, on purpose: when the law moves, finance changes the
 * settings and reruns, and nothing here needs touching.
 */
class PayrollCalculator
{
    /**
     * One line on a payslip.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    protected function line(string $label, float $amount, array $extra = []): array
    {
        return ['label' => $label, 'amount' => round($amount, 2), ...$extra];
    }

    /**
     * The whole payslip for one person, as it will be frozen onto the row.
     *
     * @param  float  $annualRent  What they pay in rent, from their HR record,
     *                             which buys relief against tax.
     * @return array{
     *     currency: string,
     *     earnings: array<int, array<string, mixed>>,
     *     deductions: array<int, array<string, mixed>>,
     *     gross_pay: float,
     *     total_earnings: float,
     *     total_deductions: float,
     *     net_pay: float,
     *     employer_pension: float,
     *     annual: array<string, float>
     * }
     */
    public function forProfile(SalaryProfile $profile, PayrollSettings $settings, float $annualRent = 0): array
    {
        $gross = $profile->annual_gross;

        $basic = $this->percent($gross, $settings->basic_percent);
        $housing = $this->percent($gross, $settings->housing_percent);
        $transport = $this->percent($gross, $settings->transport_percent);
        // Whatever is left over, taken as the remainder rather than as its own
        // percentage so the four lines always add back up to the gross.
        $other = round($gross - $basic - $housing - $transport, 2);

        // Pension is assessed on basic, housing and transport together.
        $pensionable = $basic + $housing + $transport;

        $employeePension = $profile->pension_applies
            ? $this->percent($pensionable, $settings->pension_employee_percent)
            : 0.0;

        $employerPension = $profile->pension_applies
            ? $this->percent($pensionable, $settings->pension_employer_percent)
            : 0.0;

        $nhf = $profile->nhf_applies
            ? $this->percent($basic, $settings->nhf_percent)
            : 0.0;

        $rentRelief = $this->rentRelief($annualRent, $settings);

        // Relief and the statutory deductions come off before tax is charged.
        // Floored at zero: a large relief must not turn into negative tax.
        $taxable = max(0, $gross - $employeePension - $nhf - $rentRelief);

        $tax = $this->taxOn($taxable, $settings);

        $earnings = array_values(array_filter([
            $this->line('Basic salary', $basic / 12),
            $this->line('Housing allowance', $housing / 12),
            $this->line('Transport allowance', $transport / 12),
            $other > 0 ? $this->line('Other allowances', $other / 12) : null,
        ]));

        $deductions = array_values(array_filter([
            $employeePension > 0 ? $this->line(
                'Pension',
                $employeePension / 12,
                ['basis' => "{$settings->pension_employee_percent}% of basic, housing and transport"],
            ) : null,
            $nhf > 0 ? $this->line(
                'National Housing Fund',
                $nhf / 12,
                ['basis' => "{$settings->nhf_percent}% of basic"],
            ) : null,
            $tax > 0 ? $this->line(
                'PAYE tax',
                $tax / 12,
                ['basis' => 'Charged on '.$this->money($taxable).' a year after relief'],
            ) : null,
        ]));

        // Totalled from the rounded lines rather than from the annual figures,
        // so the payslip a person reads adds up exactly as printed.
        $totalEarnings = round(array_sum(array_column($earnings, 'amount')), 2);
        $totalDeductions = round(array_sum(array_column($deductions, 'amount')), 2);

        return [
            'currency' => $settings->currency,
            'earnings' => $earnings,
            'deductions' => $deductions,
            'gross_pay' => $totalEarnings,
            'total_earnings' => $totalEarnings,
            'total_deductions' => $totalDeductions,
            'net_pay' => round($totalEarnings - $totalDeductions, 2),
            'employer_pension' => round($employerPension / 12, 2),
            // The annual working, for the payroll page. Not shown on a
            // payslip, but it is what finance checks a run against.
            'annual' => [
                'gross' => round($gross, 2),
                'pensionable' => round($pensionable, 2),
                'employee_pension' => round($employeePension, 2),
                'employer_pension' => round($employerPension, 2),
                'nhf' => round($nhf, 2),
                'rent_relief' => round($rentRelief, 2),
                'taxable' => round($taxable, 2),
                'tax' => round($tax, 2),
            ],
        ];
    }

    /**
     * Relief on rent actually paid, capped. Somebody with no rent on file
     * gets none rather than an assumed figure.
     */
    public function rentRelief(float $annualRent, PayrollSettings $settings): float
    {
        if ($annualRent <= 0) {
            return 0.0;
        }

        return round(min(
            $this->percent($annualRent, $settings->rent_relief_percent),
            $settings->rent_relief_cap,
        ), 2);
    }

    /**
     * Annual tax, charged band by band. Each band is charged only on the slice
     * of income that falls inside it, which is what makes the whole thing
     * progressive rather than a cliff at every threshold.
     */
    public function taxOn(float $taxable, PayrollSettings $settings): float
    {
        $tax = 0.0;
        $floor = 0.0;

        foreach ($settings->orderedBands() as $band) {
            if ($taxable <= $floor) {
                break;
            }

            $ceiling = $band['up_to'] ?? INF;
            $slice = min($taxable, $ceiling) - $floor;

            if ($slice > 0) {
                $tax += $this->percent($slice, $band['rate']);
            }

            if ($band['up_to'] === null) {
                break;
            }

            $floor = $ceiling;
        }

        return round($tax, 2);
    }

    protected function percent(float $of, float $rate): float
    {
        return round($of * $rate / 100, 2);
    }

    protected function money(float $amount): string
    {
        return number_format($amount, 2);
    }
}
