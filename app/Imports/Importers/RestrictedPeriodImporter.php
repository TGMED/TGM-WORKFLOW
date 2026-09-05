<?php

namespace App\Imports\Importers;

use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Imports\BaseImporter;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Models\LeaveRestrictedPeriod;
use App\Models\LeaveType;
use Illuminate\Validation\Rule;

/**
 * Stretches of the calendar closed to leave — a stock count, a year-end
 * close. Loaded in bulk because they are usually decided a year at a time.
 */
class RestrictedPeriodImporter extends BaseImporter
{
    public function key(): string
    {
        return 'restricted-periods';
    }

    public function label(): string
    {
        return 'Restricted periods';
    }

    public function description(): string
    {
        return 'Stretches of the calendar closed to leave, with the exemptions that let certain people or certain types of leave through anyway.';
    }

    public function permission(): Permission
    {
        return Permission::ManageRequestSettings;
    }

    /**
     * @return array<int, string>
     */
    public function dependsOn(): array
    {
        return ['leave-types'];
    }

    public function matchedOn(): string
    {
        return 'the period name together with its start date';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'Closing a window is a rule for the bookings to come. Leave already granted over those days stands.',
            'An employee whose profile carries no marital status is never exempt: an unset status would otherwise be the way round the block.',
            'Marital statuses accepted are: '.implode(', ', (array) config('profile.marital_statuses')).'.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        return [
            ImportColumn::required('name', 'What the closed period is called. Staff see this when a booking is turned away.', 'Year-end stock count'),
            ImportColumn::required('start_date', 'First day closed to leave.', '2026-12-15', format: 'Date'),
            ImportColumn::required('end_date', 'Last day closed to leave. Must not be before the start.', '2026-12-31', format: 'Date'),
            ImportColumn::make('reason', 'Why the window is closed, shown with the refusal.', 'The warehouse count runs across these two weeks.'),
            ImportColumn::make(
                'exempt_marital_statuses',
                'Marital statuses that may book over the period anyway, separated by semicolons.',
                'Married',
                format: 'Semicolon-separated statuses',
                accepts: (array) config('profile.marital_statuses'),
            ),
            ImportColumn::make(
                'exempt_leave_types',
                'Leave types the period does not cover at all, by slug, separated by semicolons.',
                'compassionate;maternity',
                format: 'Semicolon-separated leave type slugs',
            ),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $name = $row->string('name');
        $start = $row->date('start_date');

        $existing = $name === null || $start === null
            ? null
            : LeaveRestrictedPeriod::query()
                ->where('name', $name)
                ->where('start_date', $start->toDateString())
                ->first();

        if ($existing !== null && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($existing !== null && $duplicates === ImportDuplicates::Reject) {
            $this->reject("{$name} already runs from {$existing->rangeLabel()}.");
        }

        $data = $this->validate(
            [
                'name' => $name,
                'reason' => $row->string('reason'),
                'start_date' => $start?->toDateString(),
                'end_date' => $row->date('end_date')?->toDateString(),
                'exempt_marital_statuses' => $row->list('exempt_marital_statuses'),
            ],
            [
                'name' => ['required', 'string', 'max:120'],
                'reason' => ['nullable', 'string', 'max:255'],
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'exempt_marital_statuses' => ['array'],
                'exempt_marital_statuses.*' => [Rule::in((array) config('profile.marital_statuses'))],
            ],
            [
                'start_date.required' => 'Give a start date the import can read.',
                'end_date.required' => 'Give an end date the import can read.',
                'end_date.after_or_equal' => 'The period cannot end before it starts.',
                'exempt_marital_statuses.*.in' => 'That is not a marital status the profile offers.',
            ],
        );

        $typeIds = $this->leaveTypeIds($row);

        if ($existing !== null) {
            $existing->update($data);
            $existing->leaveTypes()->sync($typeIds);

            $result->updated();

            return;
        }

        $period = LeaveRestrictedPeriod::query()->create([
            ...$data,
            // Imported rather than authored. The audit trail already carries
            // who ran the import, so there is no person to name here.
            'user_id' => null,
        ]);

        $period->leaveTypes()->sync($typeIds);

        $result->created();
    }

    /**
     * @return array<int, int>
     */
    private function leaveTypeIds(ImportRow $row): array
    {
        $slugs = $row->list('exempt_leave_types');

        if ($slugs === []) {
            return [];
        }

        /** @var array<string, int> $found */
        $found = LeaveType::query()
            ->whereIn('slug', $slugs)
            ->pluck('id', 'slug')
            ->all();

        $missing = array_diff($slugs, array_keys($found));

        if ($missing !== []) {
            $this->reject('No leave type has the slug '.implode(' or ', $missing).'.');
        }

        return array_values($found);
    }
}
