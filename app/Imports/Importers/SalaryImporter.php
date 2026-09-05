<?php

namespace App\Imports\Importers;

use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Imports\BaseImporter;
use App\Imports\Concerns\ResolvesStaff;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Models\SalaryProfile;

/**
 * What people are paid.
 *
 * Behind the payroll permission rather than the staff one, deliberately:
 * knowing what everyone in the company earns is not something managing staff
 * should carry along with it, and a bulk upload must not be the way round
 * that.
 */
class SalaryImporter extends BaseImporter
{
    use ResolvesStaff;

    public function key(): string
    {
        return 'salaries';
    }

    public function label(): string
    {
        return 'Salaries';
    }

    public function description(): string
    {
        return 'The annual package each person is on, and whether the pension and housing fund schemes apply to them.';
    }

    public function permission(): Permission
    {
        return Permission::ManagePayroll;
    }

    /**
     * @return array<int, string>
     */
    public function dependsOn(): array
    {
        return ['staff'];
    }

    public function matchedOn(): string
    {
        return 'the person, by staff ID or email. Each person has one salary, which is replaced rather than added to';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'One number per person. Basic, housing, pension and tax are all worked out from the annual package and the payroll settings, so there is one figure to keep right rather than eight.',
            'Administrators are not on the payroll and are rejected. Only people who work a shift draw a salary through this system.',
            'A salary is replaced rather than versioned. The history that matters is the audit trail on the row and the payslips already run, both of which outlive an edit.',
            'Changing a salary does not change a draft payroll run on its own. Rebuild the run afterwards to pick the new figures up.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        return [
            ...$this->staffColumns(),

            ImportColumn::required('annual_gross', 'The whole package for a year, before anything comes off.', '4800000', format: 'Amount'),
            ImportColumn::boolean('pension_applies', 'Whether the pension scheme applies to this person.'),
            ImportColumn::boolean('nhf_applies', 'Whether the national housing fund applies to this person.'),
            ImportColumn::make('effective_from', 'The date this package took effect.', '2026-01-01', format: 'Date'),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $user = $this->staffFor($row);

        if (! $user->clocksIn()) {
            $this->reject("{$user->name} runs the system rather than draws a salary through it, so there is no salary to set.");
        }

        if (! $user->is_active) {
            $this->reject("{$user->name} is deactivated. Reactivate them on the staff sheet before setting a salary.");
        }

        $existing = SalaryProfile::query()->where('user_id', $user->id)->first();

        if ($existing !== null && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($existing !== null && $duplicates === ImportDuplicates::Reject) {
            $this->reject("{$user->name} already has a salary on file.");
        }

        $data = $this->validate(
            [
                'annual_gross' => $row->float('annual_gross'),
                'pension_applies' => $row->boolean('pension_applies'),
                'nhf_applies' => $row->boolean('nhf_applies'),
                'effective_from' => $row->date('effective_from')?->toDateString(),
            ],
            [
                'annual_gross' => [$existing === null ? 'required' : 'nullable', 'numeric', 'min:1', 'max:9999999999'],
                'pension_applies' => ['nullable', 'boolean'],
                'nhf_applies' => ['nullable', 'boolean'],
                'effective_from' => ['nullable', 'date'],
            ],
            [
                'annual_gross.required' => 'Give the annual package. The import cannot work one out.',
                'annual_gross.numeric' => 'The annual package has to be a number. Take out any currency symbol.',
                'annual_gross.min' => 'A salary has to be more than nothing.',
            ],
        );

        if ($existing !== null) {
            $existing->update($this->present($data));
            $result->updated();

            return;
        }

        SalaryProfile::query()->create([
            ...$this->present($data),
            'user_id' => $user->id,
        ]);

        $result->created();
    }
}
