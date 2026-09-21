<?php

namespace App\Http\Controllers;

use App\Models\Payslip;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
                'pdf_url' => route('payslips.pdf', $slip),
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
        $user = $this->reader($request, $payslip);

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
            'employee' => $this->employee($payslip, $user),
            'company' => config('app.name'),
            'pdf_url' => route('payslips.pdf', $payslip),
        ]);
    }

    /**
     * The same payslip as a file to keep.
     *
     * Rendered from a Blade view rather than from the Vue page: a PDF is
     * produced without a browser, so the document is written once for dompdf
     * and does not depend on the app's stylesheet surviving a redesign.
     */
    public function pdf(Request $request, Payslip $payslip): HttpResponse
    {
        $user = $this->reader($request, $payslip);

        $pdf = Pdf::loadView('payslips.pdf', [
            'payslip' => $payslip,
            'employee' => $this->employee($payslip, $user),
            'company' => config('app.name'),
            'issued' => $payslip->run->finalised_at?->format('j F Y'),
            // Passed in rather than formatted in the view, so the figures on
            // the file read exactly as the ones on the screen.
            'show' => fn (float $amount): string => $this->money($amount, $payslip->currency),
        ])->setPaper('a4');

        return $pdf->download($this->filename($payslip, $user));
    }

    /**
     * Whose payslip this is, refusing anyone else.
     *
     * Yours and finalised, or it does not exist as far as you are concerned.
     * Nobody reads a colleague's payslip here, whatever they may be allowed to
     * do on the payroll page.
     */
    protected function reader(Request $request, Payslip $payslip): User
    {
        $user = $request->user();

        abort_unless($payslip->user_id === $user->id, 404);
        abort_unless($payslip->run->status->isDraft() === false, 404);

        $payslip->load('user.profile:id,user_id,bank_name,account_number');

        return $user;
    }

    /**
     * @return array<string, string|null>
     */
    protected function employee(Payslip $payslip, User $user): array
    {
        return [
            'name' => $user->name,
            'employee_id' => $user->employee_id,
            'department' => $user->department?->name,
            'position' => $user->position,
            'bank_name' => $payslip->user->profile?->bank_name,
            // Only the tail of the account number: a payslip gets emailed
            // on and printed out, and the whole number does not need to
            // travel with it.
            'account_tail' => $this->accountTail($payslip->user->profile?->account_number),
        ];
    }

    /**
     * A name the file can be filed under without renaming it: who it is for,
     * and which month, in an order that sorts.
     */
    protected function filename(Payslip $payslip, User $user): string
    {
        $who = $user->employee_id ?? str($user->name)->slug()->value();

        return sprintf('payslip-%s-%d-%02d.pdf', $who, $payslip->year, $payslip->month);
    }

    protected function money(float $amount, string $currency): string
    {
        return $currency.' '.number_format($amount, 2);
    }

    protected function accountTail(?string $account): ?string
    {
        if ($account === null || strlen($account) < 4) {
            return null;
        }

        return '••••'.substr($account, -4);
    }
}
