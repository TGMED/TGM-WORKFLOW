<?php

namespace App\Imports\Importers;

use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Imports\BaseImporter;
use App\Imports\Concerns\ResolvesStaff;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Support\Countries;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * The HR record that sits beside the user row: identity, origin, medical,
 * and the bank and pension details payroll needs.
 *
 * Ordinarily this is a record the employee keeps themselves. Importing it is
 * for the day a company arrives with the whole file already filled in
 * somewhere else, and would otherwise be locked out of the app by the
 * profile-complete gate until three hundred people typed it in again.
 */
class EmployeeProfileImporter extends BaseImporter
{
    use ResolvesStaff;

    public function key(): string
    {
        return 'employee-profiles';
    }

    public function label(): string
    {
        return 'Employee profiles';
    }

    public function description(): string
    {
        return 'The HR record behind each person: identity, origin, medical details, and the bank and pension details payroll reads.';
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
        return 'the person, by staff ID or email. Each person has one profile, which is created if it is not there yet';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'A profile counts as complete, and stops blocking the person from using the app, once first name, last name, gender, date of birth, country and state of origin are all filled in and the staff record carries a phone number.',
            'The phone number lives on the staff record rather than here, and is set from the phone column on this sheet if it is given.',
            'Bank details are nullable throughout on purpose: an incomplete set must not stop somebody using the app, and payroll chases them separately.',
            'Titles, genders, marital statuses, blood groups, genotypes, religions, banks and pension administrators are closed lists. Spellings must match exactly; the reference file names every value each column accepts.',
            'Country of origin is a two-letter ISO code, not a country name.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        /** @var array<int, string> $titles */
        $titles = (array) config('profile.titles');
        /** @var array<int, string> $genders */
        $genders = (array) config('profile.genders');
        /** @var array<int, string> $marital */
        $marital = (array) config('profile.marital_statuses');
        /** @var array<int, string> $bloodGroups */
        $bloodGroups = (array) config('profile.blood_groups');
        /** @var array<int, string> $genotypes */
        $genotypes = (array) config('profile.genotypes');
        /** @var array<int, string> $religions */
        $religions = (array) config('profile.religions');
        /** @var array<int, string> $banks */
        $banks = (array) config('profile.banks');
        /** @var array<int, string> $pfas */
        $pfas = (array) config('profile.pension_administrators');

        return [
            ...$this->staffColumns(),

            ImportColumn::make('first_name', 'Given name. Needed before the profile counts as complete.', 'Ada'),
            ImportColumn::make('last_name', 'Family name. Needed before the profile counts as complete.', 'Eze'),
            ImportColumn::make('other_names', 'Any middle names.', 'Chidinma'),
            ImportColumn::make('title', 'How they are addressed.', 'Mrs', format: 'One of the listed titles', accepts: $titles),
            ImportColumn::make('gender', 'Needed before the profile counts as complete.', 'Female', accepts: $genders),
            ImportColumn::make('date_of_birth', 'Needed before the profile counts as complete, and read by birthday leave and birthday greetings.', '1994-06-12', format: 'Date'),
            ImportColumn::make('place_of_birth', 'Where they were born.', 'Enugu'),
            ImportColumn::make('marital_status', 'Read by restricted periods, which can exempt certain statuses.', 'Married', accepts: $marital),
            ImportColumn::make('mothers_maiden_name', 'Held as an identity check.', 'Okafor'),
            ImportColumn::make('attendance_id', 'The badge number the clocking hardware knows this person by, which is not the staff ID.', 'B-1148'),

            ImportColumn::make('phone', 'Primary phone. Written to the staff record, not the profile, and needed before the profile counts as complete.', '+2348012345678'),
            ImportColumn::make('alternate_phone', 'A second number to try.', '+2348098765432'),
            ImportColumn::make('alternate_email', 'A personal email, for when the work one is gone.', 'ada.eze@gmail.com', format: 'Email address'),

            ImportColumn::make('spouse_name', 'Their spouse\'s name.', 'Emeka Eze'),
            ImportColumn::make('spouse_phone', 'Their spouse\'s number.', '+2348011122233'),
            ImportColumn::make('number_of_kids', 'How many children they have.', '2', format: 'Whole number, 0 to 50'),

            ImportColumn::make('blood_group', 'Held so a site can act in an emergency.', 'O+', accepts: $bloodGroups),
            ImportColumn::make('genotype', 'Held so a site can act in an emergency.', 'AA', accepts: $genotypes),
            ImportColumn::make('allergies', 'Anything a first responder should know.', 'Penicillin'),
            ImportColumn::make('medical_history', 'Any continuing condition worth recording.', 'Asthma, managed'),
            ImportColumn::make('religion', 'Recorded where the employee has given it.', 'Christianity', accepts: $religions),

            ImportColumn::make('national_id_number', 'National identification number.', '12345678901'),
            ImportColumn::make('country_of_origin', 'Two-letter ISO country code. Needed before the profile counts as complete.', 'NG', format: 'Two-letter ISO country code'),
            ImportColumn::make('state_of_origin', 'Needed before the profile counts as complete.', 'Enugu State'),
            ImportColumn::make('local_government', 'Local government area of origin.', 'Nsukka'),

            ImportColumn::make('bank_name', 'Where salary is paid.', 'Guaranty Trust Bank', accepts: $banks),
            ImportColumn::make('account_number', 'The salary account.', '0123456789'),
            ImportColumn::make('account_name', 'The name the account is held in.', 'Ada Chidinma Eze'),
            ImportColumn::make('bvn', 'Bank verification number.', '22123456789'),
            ImportColumn::make('swift_code', 'For payments from outside the country.', 'GTBINGLA'),
            ImportColumn::make('sort_code', 'The branch sort code.', '058152036'),
            ImportColumn::make('annual_rent', 'Yearly rent, which the housing relief in payroll reads.', '1200000', format: 'Amount'),

            ImportColumn::make('rsa_number', 'Retirement savings account number.', 'PEN100123456789'),
            ImportColumn::make('pfa_name', 'The pension fund administrator holding it.', 'Stanbic IBTC Pension Managers', accepts: $pfas),
            ImportColumn::make('tax_identification_number', 'Tax identification number.', '12345678-0001'),
            ImportColumn::make('nhf_number', 'National housing fund number.', 'NHF1234567'),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $user = $this->staffFor($row);
        $profile = $user->profile;

        // A profile row that exists but was never filled in is not a record
        // anybody would call a duplicate, so it does not trip the skip and
        // reject modes.
        $started = $profile !== null && $profile->completed_at !== null;

        if ($started && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($started && $duplicates === ImportDuplicates::Reject) {
            $this->reject("{$user->name} already has a completed profile.");
        }

        $data = $this->validate($this->values($row), $this->rules(), [
            'country_of_origin.in' => 'That is not a country code. Use the two-letter ISO code, such as NG.',
            'date_of_birth.before' => 'That date of birth is for somebody under 16.',
            'title.in' => 'That is not a title the profile offers.',
            'gender.in' => 'That is not a gender the profile offers.',
            'marital_status.in' => 'That is not a marital status the profile offers.',
            'bank_name.in' => 'That bank is not on the list. Use "Other" if it is missing.',
            'pfa_name.in' => 'That pension administrator is not on the list. Use "Other" if it is missing.',
        ]);

        // The phone lives on the user, where the rest of the app reads it,
        // and is part of what makes a profile count as complete.
        $phone = $data['phone'] ?? null;
        unset($data['phone']);

        if ($phone !== null) {
            $user->update(['phone' => $phone]);
        }

        $profile ??= $user->profile()->make();
        $profile->fill($this->present($data));

        // users.name stays the display name and is recomposed from the parts
        // whenever the profile is saved, exactly as the profile page does it.
        $display = trim(implode(' ', array_filter([
            $profile->first_name,
            $profile->other_names,
            $profile->last_name,
        ])));

        if ($display !== '') {
            $user->update(['name' => $display]);
        }

        $profile->user()->associate($user);
        $profile->setRelation('user', $user->fresh() ?? $user);

        // Set the first time every required field is filled, so reporting can
        // tell a record that was finished from one that never was.
        if ($profile->completed_at === null && $profile->isComplete()) {
            $profile->completed_at = Carbon::now();
        }

        $wasThere = $profile->exists;
        $profile->save();

        $wasThere ? $result->updated() : $result->created();
    }

    /**
     * @return array<string, mixed>
     */
    private function values(ImportRow $row): array
    {
        $text = [
            'first_name', 'last_name', 'other_names', 'title', 'gender',
            'place_of_birth', 'marital_status', 'mothers_maiden_name',
            'attendance_id', 'spouse_name', 'spouse_phone', 'blood_group',
            'genotype', 'allergies', 'medical_history', 'religion',
            'national_id_number', 'state_of_origin', 'local_government',
            'alternate_phone', 'alternate_email', 'bank_name',
            'account_number', 'account_name', 'bvn', 'swift_code',
            'sort_code', 'rsa_number', 'pfa_name',
            'tax_identification_number', 'nhf_number', 'phone',
        ];

        $values = [];

        foreach ($text as $column) {
            $values[$column] = $row->string($column);
        }

        $country = $row->string('country_of_origin');

        return [
            ...$values,
            'country_of_origin' => $country === null ? null : mb_strtoupper($country),
            'date_of_birth' => $row->date('date_of_birth')?->toDateString(),
            'number_of_kids' => $row->integer('number_of_kids'),
            'annual_rent' => $row->float('annual_rent'),
        ];
    }

    /**
     * The same rules the profile page enforces, with everything nullable:
     * a bulk load fills in what the company has and leaves the rest for the
     * employee, rather than refusing a row over a field nobody knows yet.
     *
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'other_names' => ['nullable', 'string', 'max:80'],
            'title' => ['nullable', 'string', Rule::in((array) config('profile.titles'))],
            'gender' => ['nullable', 'string', Rule::in((array) config('profile.genders'))],
            'date_of_birth' => ['nullable', 'date', 'before:'.now()->subYears(16)->toDateString()],
            'place_of_birth' => ['nullable', 'string', 'max:120'],
            'marital_status' => ['nullable', 'string', Rule::in((array) config('profile.marital_statuses'))],
            'mothers_maiden_name' => ['nullable', 'string', 'max:120'],
            'attendance_id' => ['nullable', 'string', 'max:40'],

            'phone' => ['nullable', 'string', 'max:30'],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'alternate_email' => ['nullable', 'string', 'email', 'max:190'],

            'spouse_name' => ['nullable', 'string', 'max:120'],
            'spouse_phone' => ['nullable', 'string', 'max:30'],
            'number_of_kids' => ['nullable', 'integer', 'min:0', 'max:50'],

            'blood_group' => ['nullable', 'string', Rule::in((array) config('profile.blood_groups'))],
            'genotype' => ['nullable', 'string', Rule::in((array) config('profile.genotypes'))],
            'religion' => ['nullable', 'string', Rule::in((array) config('profile.religions'))],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'medical_history' => ['nullable', 'string', 'max:2000'],
            'national_id_number' => ['nullable', 'string', 'max:40'],

            'country_of_origin' => ['nullable', 'string', 'size:2', Rule::in(Countries::codes())],
            'state_of_origin' => ['nullable', 'string', 'max:80'],
            'local_government' => ['nullable', 'string', 'max:80'],

            'bank_name' => ['nullable', 'string', Rule::in((array) config('profile.banks'))],
            'account_number' => ['nullable', 'string', 'max:20'],
            'account_name' => ['nullable', 'string', 'max:120'],
            'bvn' => ['nullable', 'string', 'max:20'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'sort_code' => ['nullable', 'string', 'max:20'],
            'annual_rent' => ['nullable', 'numeric', 'min:0'],

            'rsa_number' => ['nullable', 'string', 'max:30'],
            'pfa_name' => ['nullable', 'string', Rule::in((array) config('profile.pension_administrators'))],
            'tax_identification_number' => ['nullable', 'string', 'max:30'],
            'nhf_number' => ['nullable', 'string', 'max:30'],
        ];
    }
}
