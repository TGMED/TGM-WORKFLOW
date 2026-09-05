<?php

namespace App\Services;

use App\Models\PayrollRun;
use App\Models\PayrollSettings;
use App\Models\Payslip;
use App\Models\SalaryProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Builds a month's payslips from the salaries on file.
 *
 * Building is repeatable while the run is a draft: correcting a salary and
 * rebuilding is the intended way to fix a mistake, so the run is emptied and
 * filled again rather than patched.
 */
class PayrollRunBuilder
{
    public function __construct(protected PayrollCalculator $calculator) {}

    /**
     * (Re)build every payslip in a draft run. Returns how many were written.
     */
    public function build(PayrollRun $run): int
    {
        $settings = PayrollSettings::current();

        // Who is on the payroll this month, resolved first: administrators
        // run the system rather than draw a salary through it, and somebody
        // deactivated since the last run is no longer paid.
        $payable = User::query()->active()->clocksIn()->pluck('id');

        $profiles = SalaryProfile::query()
            ->with(['user' => fn ($query) => $query->with('profile:id,user_id,annual_rent')])
            ->whereIn('user_id', $payable)
            ->get();

        return DB::transaction(function () use ($run, $settings, $profiles): int {
            // Emptied first: somebody taken off the payroll since the last
            // build must not be left behind with a stale payslip.
            $run->payslips()->delete();

            $written = 0;

            foreach ($profiles as $profile) {
                $breakdown = $this->calculator->forProfile(
                    $profile,
                    $settings,
                    (float) ($profile->user->profile->annual_rent ?? 0),
                );

                Payslip::query()->create([
                    'payroll_run_id' => $run->id,
                    'user_id' => $profile->user_id,
                    'year' => $run->year,
                    'month' => $run->month,
                    'currency' => $breakdown['currency'],
                    'earnings' => $breakdown['earnings'],
                    'deductions' => $breakdown['deductions'],
                    'gross_pay' => $breakdown['gross_pay'],
                    'total_earnings' => $breakdown['total_earnings'],
                    'total_deductions' => $breakdown['total_deductions'],
                    'net_pay' => $breakdown['net_pay'],
                    'employer_pension' => $breakdown['employer_pension'],
                ]);

                $written++;
            }

            return $written;
        });
    }
}
