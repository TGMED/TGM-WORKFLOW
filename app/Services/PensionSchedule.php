<?php

namespace App\Services;

use App\Models\PayrollRun;
use App\Models\Payslip;
use Illuminate\Support\Collection;

/**
 * What is owed to the pension administrator for a month, person by person.
 *
 * Built from the payslips as they were frozen rather than recalculated, for
 * the same reason a payslip is frozen: the schedule has to agree with what
 * people were actually paid, not with what today's rates would have paid them.
 */
class PensionSchedule
{
    /**
     * One line per person on the run, with both contributions and the details
     * the administrator needs to credit the right account.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function forRun(PayrollRun $run): Collection
    {
        /** @var Collection<int, array<string, mixed>> $lines */
        $lines = $run->payslips()
            ->with(['user:id,name,employee_id', 'user.profile:id,user_id,rsa_number,pfa_name'])
            ->get()
            ->filter(fn (Payslip $slip): bool => $slip->employee_pension > 0 || $slip->employer_pension > 0)
            ->sortBy(fn (Payslip $slip): string => $slip->user->name)
            ->map(fn (Payslip $slip): array => [
                'user_id' => $slip->user_id,
                'name' => $slip->user->name,
                'employee_id' => $slip->user->employee_id,
                'rsa_number' => $slip->user->profile?->rsa_number,
                'pfa_name' => $slip->user->profile?->pfa_name,
                'employee' => $slip->employee_pension,
                'employer' => $slip->employer_pension,
                'total' => round($slip->employee_pension + $slip->employer_pension, 2),
                // Without an RSA number the money has nowhere to land, so the
                // gap is carried on the line rather than left to be noticed
                // when the administrator rejects the file.
                'ready' => filled($slip->user->profile?->rsa_number),
            ])
            ->values();

        return $lines;
    }

    /**
     * The totals the transfer is made against.
     *
     * @param  Collection<int, array<string, mixed>>  $lines
     * @return array<string, float|int>
     */
    public function totals(Collection $lines): array
    {
        return [
            'people' => $lines->count(),
            'employee' => round((float) $lines->sum('employee'), 2),
            'employer' => round((float) $lines->sum('employer'), 2),
            'total' => round((float) $lines->sum('total'), 2),
            'missing_rsa' => $lines->where('ready', false)->count(),
        ];
    }
}
