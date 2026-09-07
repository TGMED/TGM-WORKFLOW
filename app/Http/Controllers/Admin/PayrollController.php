<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PayrollRunStatus;
use App\Http\Controllers\Controller;
use App\Models\PayrollRun;
use App\Models\PayrollSettings;
use App\Models\Payslip;
use App\Models\User;
use App\Services\PayrollRunBuilder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Payroll: the salaries on file, the rules pay is worked out under, and the
 * monthly runs.
 *
 * A run is built as a draft, checked against the totals on this page, and
 * only then finalised — which is the single moment payslips become visible
 * to staff. Nothing here edits a payslip directly: a wrong figure is fixed by
 * correcting the salary and rebuilding, so a payslip always matches the
 * salary that produced it.
 */
class PayrollController extends Controller
{
    public function __construct(protected PayrollRunBuilder $builder) {}

    public function index(): Response
    {
        $settings = PayrollSettings::current();

        $runs = PayrollRun::query()
            ->with(['createdBy:id,name', 'finalisedBy:id,name'])
            ->withCount('payslips')
            ->withSum('payslips', 'net_pay')
            ->withSum('payslips', 'gross_pay')
            ->inPeriodOrder()
            ->limit(24)
            ->get()
            ->map(fn (PayrollRun $run): array => [
                'id' => $run->id,
                'year' => $run->year,
                'month' => $run->month,
                'period_label' => $run->periodLabel(),
                'status' => $run->status->value,
                'status_label' => $run->status->label(),
                'status_tone' => $run->status->tone(),
                'is_draft' => $run->isDraft(),
                'headcount' => (int) $run->payslips_count,
                'gross_total' => (float) ($run->payslips_sum_gross_pay ?? 0),
                'net_total' => (float) ($run->payslips_sum_net_pay ?? 0),
                'created_by' => $run->createdBy?->name,
                'finalised_by' => $run->finalisedBy?->name,
                'finalised_at' => $run->finalised_at?->toIso8601String(),
            ])
            ->values();

        // Everybody who draws pay, with their salary where one is on file.
        // The ones without are the point of the list: an empty salary is how
        // somebody quietly goes unpaid for a month.
        $staff = User::query()
            ->active()
            ->clocksIn()
            ->with(['salaryProfile', 'profile:id,user_id,annual_rent'])
            ->orderBy('name')
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'initials' => $user->initials,
                'employee_id' => $user->employee_id,
                'department' => $user->department,
                'position' => $user->position,
                'annual_gross' => $user->salaryProfile?->annual_gross,
                'monthly_gross' => $user->salaryProfile?->monthlyGross(),
                'pension_applies' => $user->salaryProfile->pension_applies ?? true,
                'nhf_applies' => $user->salaryProfile->nhf_applies ?? true,
                'effective_from' => $user->salaryProfile?->effective_from?->toDateString(),
                'annual_rent' => (float) ($user->profile->annual_rent ?? 0),
            ])
            ->values();

        return Inertia::render('admin/Payroll', [
            'runs' => $runs,
            'staff' => $staff,
            'settings' => $this->settingsPayload($settings),
            'unpaid' => $staff->whereNull('annual_gross')->count(),
            'next_period' => $this->nextPeriod(),
        ]);
    }

    /**
     * One run in detail: every payslip it produced.
     */
    public function show(PayrollRun $run): Response
    {
        $run->load(['createdBy:id,name', 'finalisedBy:id,name']);

        $payslips = $run->payslips()
            ->with('user:id,name,employee_id,department')
            ->get()
            ->sortBy(fn (Payslip $slip): string => $slip->user->name)
            ->map(fn (Payslip $slip): array => [
                'id' => $slip->id,
                'user' => [
                    'id' => $slip->user->id,
                    'name' => $slip->user->name,
                    'employee_id' => $slip->user->employee_id,
                    'department' => $slip->user->department,
                ],
                'currency' => $slip->currency,
                'earnings' => $slip->earnings,
                'deductions' => $slip->deductions,
                'gross_pay' => $slip->gross_pay,
                'total_deductions' => $slip->total_deductions,
                'net_pay' => $slip->net_pay,
                'employer_pension' => $slip->employer_pension,
            ])
            ->values();

        return Inertia::render('admin/PayrollRun', [
            'run' => [
                'id' => $run->id,
                'period_label' => $run->periodLabel(),
                'status' => $run->status->value,
                'status_label' => $run->status->label(),
                'status_tone' => $run->status->tone(),
                'is_draft' => $run->isDraft(),
                'created_by' => $run->createdBy?->name,
                'finalised_by' => $run->finalisedBy?->name,
                'finalised_at' => $run->finalised_at?->toIso8601String(),
            ],
            'payslips' => $payslips,
            'totals' => [
                'headcount' => $payslips->count(),
                'gross' => round($payslips->sum('gross_pay'), 2),
                'deductions' => round($payslips->sum('total_deductions'), 2),
                'net' => round($payslips->sum('net_pay'), 2),
                'employer_pension' => round($payslips->sum('employer_pension'), 2),
            ],
        ]);
    }

    /**
     * Open a month and fill it. A month already run is refused rather than
     * doubled: fixing one means rebuilding the draft that exists.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ], [], ['year' => 'year', 'month' => 'month']);

        $exists = PayrollRun::query()
            ->where('year', $validated['year'])
            ->where('month', $validated['month'])
            ->exists();

        if ($exists) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'That month has already been run.',
            ]);
        }

        $run = PayrollRun::query()->create([
            'year' => $validated['year'],
            'month' => $validated['month'],
            'status' => PayrollRunStatus::Draft,
            'created_by_id' => $request->user()->id,
        ]);

        $written = $this->builder->build($run);

        return to_route('admin.payroll.show', $run)->with('toast', [
            'type' => 'success',
            'message' => "{$run->periodLabel()} drafted for {$written} ".($written === 1 ? 'person.' : 'people.'),
        ]);
    }

    /**
     * Throw the draft's payslips away and work them out again, picking up any
     * salary or rate that has changed since.
     */
    public function rebuild(PayrollRun $run): RedirectResponse
    {
        abort_unless($run->isDraft(), 403, 'A finalised run cannot be rebuilt.');

        $written = $this->builder->build($run);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Rebuilt for {$written} ".($written === 1 ? 'person.' : 'people.'),
        ]);
    }

    /**
     * Sign the run off, which is what puts the payslips in front of staff.
     */
    public function finalise(Request $request, PayrollRun $run): RedirectResponse
    {
        abort_unless($run->isDraft(), 403, 'That run is already finalised.');

        if ($run->payslips()->doesntExist()) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'There is nothing in this run to finalise.',
            ]);
        }

        $run->update([
            'status' => PayrollRunStatus::Finalised,
            'finalised_by_id' => $request->user()->id,
            'finalised_at' => now(),
        ]);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$run->periodLabel()} finalised. Staff can see their payslips.",
        ]);
    }

    /**
     * Drop a draft. A finalised run is a record of money paid and stays.
     */
    public function destroy(PayrollRun $run): RedirectResponse
    {
        abort_unless($run->isDraft(), 403, 'A finalised run cannot be deleted.');

        $period = $run->periodLabel();
        $run->delete();

        return to_route('admin.payroll.index')->with('toast', [
            'type' => 'success',
            'message' => "{$period} draft deleted.",
        ]);
    }

    /**
     * The month a run would next be opened for: the one after the latest run,
     * or this month when there is nothing on file yet.
     */
    /**
     * @return array{year: int, month: int}
     */
    protected function nextPeriod(): array
    {
        $latest = PayrollRun::query()->inPeriodOrder()->first();

        $next = $latest === null
            ? Carbon::now()->startOfMonth()
            : Carbon::create($latest->year, $latest->month, 1)->addMonth();

        return ['year' => $next->year, 'month' => $next->month];
    }

    /**
     * @return array<string, mixed>
     */
    protected function settingsPayload(PayrollSettings $settings): array
    {
        return [
            'currency' => $settings->currency,
            'basic_percent' => $settings->basic_percent,
            'housing_percent' => $settings->housing_percent,
            'transport_percent' => $settings->transport_percent,
            'other_percent' => $settings->otherPercent(),
            'pension_employee_percent' => $settings->pension_employee_percent,
            'pension_employer_percent' => $settings->pension_employer_percent,
            'nhf_percent' => $settings->nhf_percent,
            'rent_relief_percent' => $settings->rent_relief_percent,
            'rent_relief_cap' => $settings->rent_relief_cap,
            'tax_bands' => $settings->orderedBands(),
        ];
    }
}
