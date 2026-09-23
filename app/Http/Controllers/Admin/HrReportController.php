<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\HrReports;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Company-wide totals over a window, each with a CSV of exactly what is shown.
 */
class HrReportController extends Controller
{
    public function __construct(protected HrReports $reports) {}

    public function index(Request $request): Response
    {
        [$from, $to] = $this->window($request);

        return Inertia::render('admin/HrReports', [
            'filters' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'sections' => $this->reports->all($from, $to),
        ]);
    }

    public function export(Request $request, string $section): StreamedResponse
    {
        abort_unless(in_array($section, HrReports::SECTIONS, true), 404);

        [$from, $to] = $this->window($request);
        $report = $this->reports->section($section, $from, $to);

        return response()->streamDownload(function () use ($report): void {
            $handle = fopen('php://output', 'w');

            if ($handle === false) {
                return;
            }

            // A byte order mark, so Excel reads the file as UTF-8.
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $report['columns']);

            foreach ($report['rows'] as $row) {
                fputcsv($handle, array_map(fn ($cell): string => (string) ($cell ?? ''), $row));
            }

            fclose($handle);
        }, "{$section}-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The window asked for, or the year so far. Reversed dates are put the
     * right way round rather than refused.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function window(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $from = $request->filled('from') ? CarbonImmutable::parse($request->string('from')->toString()) : CarbonImmutable::today()->startOfYear();
        $to = $request->filled('to') ? CarbonImmutable::parse($request->string('to')->toString()) : CarbonImmutable::today();

        return $from->greaterThan($to) ? [$to, $from] : [$from, $to];
    }
}
