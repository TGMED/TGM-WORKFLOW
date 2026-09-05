<?php

namespace App\Imports\Importers;

use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Enums\RelationKind;
use App\Imports\BaseImporter;
use App\Imports\Concerns\ResolvesStaff;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Models\EmployeeRelation;
use Illuminate\Validation\Rule;

/**
 * Next of kin, dependants and family members. One row per person named, so
 * an employee with two dependants takes two rows.
 */
class EmployeeRelationImporter extends BaseImporter
{
    use ResolvesStaff;

    public function key(): string
    {
        return 'employee-relations';
    }

    public function label(): string
    {
        return 'Next of kin and dependants';
    }

    public function description(): string
    {
        return 'The people each employee is related to: who to ring in an emergency, who they support, and family recorded for the record.';
    }

    public function permission(): Permission
    {
        return Permission::ManageStaff;
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
        return 'the person, the kind of relation and the relation\'s name together';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'One row per person named. An employee with two dependants takes two rows.',
            'A next of kin must carry a phone number: it is the number somebody rings in an emergency, and a blank one is worse than no entry at all.',
            'Relationships accepted are: '.implode(', ', (array) config('profile.relationships')).'.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        return [
            ...$this->staffColumns(),

            ImportColumn::required(
                'kind',
                'Which of the three lists this person belongs on.',
                RelationKind::NextOfKin->value,
                accepts: array_column(RelationKind::cases(), 'value'),
            ),
            ImportColumn::required('relation_name', 'The related person\'s full name.', 'Emeka Eze'),
            ImportColumn::required('relationship', 'How they are related to the employee.', 'Spouse', accepts: (array) config('profile.relationships')),
            ImportColumn::make('relation_phone', 'Their phone number. Required for a next of kin.', '+2348011122233'),
            ImportColumn::make('relation_email', 'Their email.', 'emeka.eze@example.com', format: 'Email address'),
            ImportColumn::make('relation_date_of_birth', 'Their date of birth, which matters for dependants.', '2016-04-02', format: 'Date'),
            ImportColumn::make('relation_gender', 'Their gender.', 'Male', accepts: (array) config('profile.genders')),
            ImportColumn::make('occupation', 'What they do.', 'Teacher'),
            ImportColumn::make('relation_address', 'Where they live.', '14 Ogunlana Drive, Surulere, Lagos'),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $user = $this->staffFor($row);

        $kind = $row->string('kind');
        $name = $row->string('relation_name');

        $existing = $kind === null || $name === null
            ? null
            : EmployeeRelation::query()
                ->where('user_id', $user->id)
                ->where('kind', $kind)
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                ->first();

        if ($existing !== null && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($existing !== null && $duplicates === ImportDuplicates::Reject) {
            $this->reject("{$user->name} already has {$name} on that list.");
        }

        $data = $this->validate(
            [
                'kind' => $kind,
                'name' => $name,
                'relationship' => $row->string('relationship'),
                'phone' => $row->string('relation_phone'),
                'email' => $row->string('relation_email'),
                'date_of_birth' => $row->date('relation_date_of_birth')?->toDateString(),
                'gender' => $row->string('relation_gender'),
                'occupation' => $row->string('occupation'),
                'address' => $row->string('relation_address'),
            ],
            [
                'kind' => ['required', Rule::enum(RelationKind::class)],
                'name' => ['required', 'string', 'max:120'],
                'relationship' => ['required', 'string', Rule::in((array) config('profile.relationships'))],
                // Next of kin is the number someone rings in an emergency, so
                // it is the one list where a phone number is not optional.
                'phone' => [
                    Rule::requiredIf($kind === RelationKind::NextOfKin->value),
                    'nullable', 'string', 'max:30',
                ],
                'email' => ['nullable', 'string', 'email', 'max:190'],
                'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
                'gender' => ['nullable', 'string', Rule::in((array) config('profile.genders'))],
                'occupation' => ['nullable', 'string', 'max:120'],
                'address' => ['nullable', 'string', 'max:500'],
            ],
            [
                'kind.enum' => 'Use next_of_kin, dependant or family_member.',
                'phone.required' => 'A next of kin needs a phone number someone can call.',
                'relationship.in' => 'That is not a relationship the profile offers.',
                'date_of_birth.before_or_equal' => 'A date of birth cannot be in the future.',
            ],
            [
                'name' => 'relation name',
                'phone' => 'relation phone',
                'email' => 'relation email',
                'date_of_birth' => 'relation date of birth',
                'gender' => 'relation gender',
                'address' => 'relation address',
            ],
        );

        if ($existing !== null) {
            $existing->update($this->present($data));
            $result->updated();

            return;
        }

        $user->relations()->create($this->present($data));
        $result->created();
    }
}
