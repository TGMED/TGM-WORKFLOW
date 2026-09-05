<?php

namespace App\Imports\Importers;

use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Enums\RequestStatus;
use App\Imports\BaseImporter;
use App\Imports\Concerns\ResolvesStaff;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Support\Workdays;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Leave already taken, brought over so that this year's balances start from
 * the truth rather than from zero.
 *
 * These arrive already decided and are not sent round the approval chain:
 * they were approved somewhere else, by somebody who is not in this system,
 * and putting them in an approvals inbox would ask people to re-decide the
 * past. The policy eligibility rules are not applied either, for the same
 * reason — history does not get to fail a rule written after it.
 */
class LeaveRecordImporter extends BaseImporter
{
    use ResolvesStaff;

    public function key(): string
    {
        return 'leave-records';
    }

    public function label(): string
    {
        return 'Leave history';
    }

    public function description(): string
    {
        return 'Leave already taken, so this year\'s balances open at the right figure instead of at zero.';
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
        return ['staff', 'leave-types'];
    }

    public function matchedOn(): string
    {
        return 'the person, the leave type and the start date together';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'Rows land already decided and are never sent round the approval chain. They were approved somewhere else, and an approvals inbox full of last year\'s leave helps nobody.',
            'The policy eligibility rules — service served, probation, evidence — are not applied. History does not get to fail a rule written after it.',
            'Days are counted as working days at the person\'s own site, weekends and non-working days excluded, which is how the allowance is counted everywhere else. Give the days column only where you need to override that.',
            'Approved and pending rows both count against the allowance. Rejected and cancelled rows are recorded without spending anything.',
            'Nothing is emailed. Importing history does not write to the people it belongs to.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        return [
            ...$this->staffColumns(),

            ImportColumn::required('leave_type', 'The kind of leave, by slug.', 'annual', format: 'Leave type slug'),
            ImportColumn::required('start_date', 'First day off.', '2026-04-06', format: 'Date'),
            ImportColumn::required('end_date', 'Last day off. Must not be before the start.', '2026-04-10', format: 'Date'),
            ImportColumn::make(
                'days',
                'Working days to charge against the allowance. Left blank it is counted from the dates against the person\'s own site calendar.',
                '',
                format: 'Whole number',
            ),
            ImportColumn::make(
                'status',
                'How it was decided. Left blank the row is recorded as approved, which is what history usually is.',
                RequestStatus::Approved->value,
                format: 'approved, rejected, cancelled or pending',
                accepts: ['approved', 'rejected', 'cancelled', 'pending'],
            ),
            ImportColumn::make('reason', 'What the person gave as the reason.', 'Family holiday'),
            ImportColumn::make('decided_at', 'When it was decided. Left blank on a decided row, the end date is used.', '2026-03-28', format: 'Date'),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $user = $this->staffFor($row);

        if (! $user->clocksIn()) {
            $this->reject("{$user->name} runs the system rather than works a shift, so there is no leave to record.");
        }

        $type = $this->leaveType($row);
        $start = $row->date('start_date');

        if ($start === null) {
            $this->reject('Give a start date the import can read, such as 2026-04-06.');
        }

        $existing = LeaveRequest::query()
            ->where('user_id', $user->id)
            ->where('leave_type_id', $type->id)
            ->where('start_date', $start->toDateString())
            ->first();

        if ($existing !== null && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($existing !== null && $duplicates === ImportDuplicates::Reject) {
            $this->reject("{$user->name} already has {$type->name} recorded from {$start->toDateString()}.");
        }

        $data = $this->validate(
            [
                'start_date' => $start->toDateString(),
                'end_date' => $row->date('end_date')?->toDateString(),
                'days' => $row->integer('days'),
                'status' => $row->string('status') ?? RequestStatus::Approved->value,
                'reason' => $row->string('reason'),
                'decided_at' => $row->date('decided_at')?->toDateString(),
            ],
            [
                'start_date' => ['required', 'date'],
                'end_date' => ['required', 'date', 'after_or_equal:start_date'],
                'days' => ['nullable', 'integer', 'between:1,365'],
                'status' => ['required', Rule::in(['approved', 'rejected', 'cancelled', 'pending'])],
                'reason' => ['nullable', 'string', 'max:1000'],
                'decided_at' => ['nullable', 'date'],
            ],
            [
                'start_date.required' => 'Give a start date the import can read.',
                'end_date.required' => 'Give an end date the import can read.',
                'end_date.after_or_equal' => 'Leave cannot end before it starts.',
                'status.in' => 'Use approved, rejected, cancelled or pending.',
            ],
        );

        $status = RequestStatus::from((string) $data['status']);

        $days = $data['days'] ?? $this->workingDays(
            Carbon::parse((string) $data['start_date']),
            Carbon::parse((string) $data['end_date']),
            $user->location?->workdayNumbers() ?? [1, 2, 3, 4, 5],
        );

        if ($days < 1) {
            $this->reject('Those dates hold no working days at this person\'s site. Give the days column to charge the leave anyway.');
        }

        $decidedAt = $status === RequestStatus::Pending
            ? null
            : ($data['decided_at'] ?? $data['end_date']);

        $payload = [
            'leave_type_id' => $type->id,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'days' => $days,
            'reason' => $data['reason'],
            'status' => $status,
            'decided_at' => $decidedAt,
        ];

        if ($existing !== null) {
            $existing->update($payload);
            $result->updated();

            return;
        }

        LeaveRequest::query()->create([
            ...$payload,
            'user_id' => $user->id,
            // Nobody in this system raised it or signed it off. Leaving the
            // chain empty is the honest record: an imported row names no
            // supervisor and no relief officer because it had neither here.
            'raised_by_id' => null,
            'supervisor_id' => null,
            'relief_officer_id' => null,
            'approvals_required' => 0,
            'round' => 1,
        ]);

        $result->created();
    }

    private function leaveType(ImportRow $row): LeaveType
    {
        $slug = $row->string('leave_type');

        if ($slug === null) {
            $this->reject('Give the leave type, by slug.');
        }

        $type = LeaveType::query()->where('slug', $slug)->first();

        if ($type === null) {
            $this->reject("No leave type has the slug {$slug}. Import the leave types sheet first.");
        }

        return $type;
    }

    /**
     * @param  array<int, int>  $workdays
     */
    private function workingDays(Carbon $start, Carbon $end, array $workdays): int
    {
        return Workdays::countBetween($start, $end, $workdays);
    }
}
