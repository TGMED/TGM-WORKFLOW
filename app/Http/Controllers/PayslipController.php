<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An employee's own payslips. Only finalised runs appear: a draft is the
 * finance team's working copy and showing one would put a figure in front of
 * somebody that nobody has stood behind yet.
 */
class PayslipController extends Controller
{
    public function index(Request $request): Response
    {
        $payslips = Payslip::query()
            ->where('user_id', $request->user()->id)
            ->published()
            ->inPeriodOrder()
            ->get()
            ->map(fn (Payslip $slip): array => [
                'id' => $slip->id,
                'period_label' => $slip->periodLabel(),
                'year' => $slip->year,
                'month' => $slip->month,
                'currency' => $slip->currency,
                'gross_pay' => $slip->gross_pay,
                'total_deductions' => $slip->total_deductions,
                'net_pay' => $slip->net_pay,
            ])
            ->values();

        return Inertia::render('Payslips', [
            'payslips' => $payslips,
            'latest' => $payslips->first(),
        ]);
    }

    /**
     * One payslip in full, as a page that prints.
     */
    public function show(Request $request, Payslip $payslip): Response
    {
        $user = $request->user();

        // Yours and finalised, or it does not exist as far as you are
        // concerned. Nobody reads a colleague's payslip here, whatever they
        // may be allowed to do on the payroll page.
        abort_unless($payslip->user_id === $user->id, 404);
        abort_unless($payslip->run->status->isDraft() === false, 404);

        $payslip->load('user.profile:id,user_id,bank_name,account_number');

        return Inertia::render('Payslip', [
            'payslip' => [
                'id' => $payslip->id,
                'period_label' => $payslip->periodLabel(),
                'currency' => $payslip->currency,
                'earnings' => $payslip->earnings,
                'deductions' => $payslip->deductions,
                'gross_pay' => $payslip->gross_pay,
                'total_deductions' => $payslip->total_deductions,
                'net_pay' => $payslip->net_pay,
                'employer_pension' => $payslip->employer_pension,
                'issued_at' => $payslip->run->finalised_at?->toIso8601String(),
            ],
            'employee' => [
                'name' => $user->name,
                'employee_id' => $user->employee_id,
                'department' => $user->department,
                'position' => $user->position,
                'bank_name' => $payslip->user->profile?->bank_name,
                // Only the tail of the account number: a payslip gets emailed
                // on and printed out, and the whole number does not need to
                // travel with it.
                'account_tail' => $this->accountTail($payslip->user->profile?->account_number),
            ],
            'company' => config('app.name'),
        ]);
    }

    protected function accountTail(?string $account): ?string
    {
        if ($account === null || strlen($account) < 4) {
            return null;
        }

        return '••••'.substr($account, -4);
    }
}
