<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Services\PensionSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The pension side of a payroll run: who is owed what, whether their account
 * details are on file, and whether the money has gone.
 *
 * Read off the finalised payslips rather than recalculated, so the schedule
 * agrees with what people were actually paid.
 */
class PensionController extends Controller
{
    /**
     * The download's header row, in the order administrators usually ask for.
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'Employee ID',
        'Name',
        'RSA number',
        'PFA',
        'Employee contribution',
        'Employer contribution',
        'Total',
    ];

    public function __construct(protected PensionSchedule $schedule) {}

    public function show(PayrollRun $run): Response
    {
        $run->load('pensionRemittedBy:id,name');

        $lines = $this->schedule->forRun($run);

        return Inertia::render('admin/PensionSchedule', [
            'run' => [
                'id' => $run->id,
                'period_label' => $run->periodLabel(),
                'status_label' => $run->status->label(),
                'status_tone' => $run->status->tone(),
                'is_draft' => $run->isDraft(),
                'remitted_at' => $run->pension_remitted_at?->toIso8601String(),
                'reference' => $run->pension_reference,
                'remitted_by' => $run->pensionRemittedBy?->name,
            ],
            'lines' => $lines,
            'totals' => $this->schedule->totals($lines),
        ]);
    }

    /**
     * The schedule as the administrator wants it. Draft runs are refused: a
     * remittance file built from figures nobody has signed off is the sort of
     * mistake that takes a quarter to unpick.
     */
    public function export(PayrollRun $run): StreamedResponse
    {
        abort_if($run->isDraft(), 404);

        $lines = $this->schedule->forRun($run);
        $filename = "pension-{$run->year}-".str_pad((string) $run->month, 2, '0', STR_PAD_LEFT).'.csv';

        return response()->streamDownload(
            function () use ($lines): void {
                $handle = fopen('php://output', 'w');

                if ($handle === false) {
                    return;
                }

                // Excel opens a UTF-8 CSV as the local codepage without this.
                fwrite($handle, "\xEF\xBB\xBF");

                fputcsv($handle, self::COLUMNS);

                foreach ($lines as $line) {
                    fputcsv($handle, [
                        (string) ($line['employee_id'] ?? ''),
                        (string) $line['name'],
                        (string) ($line['rsa_number'] ?? ''),
                        (string) ($line['pfa_name'] ?? ''),
                        number_format((float) $line['employee'], 2, '.', ''),
                        number_format((float) $line['employer'], 2, '.', ''),
                        number_format((float) $line['total'], 2, '.', ''),
                    ]);
                }

                fclose($handle);
            },
            $filename,
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    /**
     * Record that the month has been paid over. Kept as a fact somebody
     * enters rather than something inferred: the app cannot see a bank
     * transfer, and pretending otherwise would be worse than not saying.
     */
    public function remit(Request $request, PayrollRun $run): RedirectResponse
    {
        if ($run->isDraft()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Sign the run off before recording a remittance against it.',
            ]);
        }

        $validated = $request->validate([
            'pension_reference' => ['required', 'string', 'max:120'],
            'pension_remitted_at' => ['nullable', 'date'],
        ]);

        $run->update([
            'pension_reference' => $validated['pension_reference'],
            'pension_remitted_at' => $validated['pension_remitted_at'] ?? Carbon::now(),
            'pension_remitted_by_id' => $request->user()->id,
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Pension for {$run->periodLabel()} is recorded as remitted.",
        ]);
    }
}
