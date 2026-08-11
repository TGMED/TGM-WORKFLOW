<?php

namespace App\Http\Requests;

use App\Models\EmployeeProfile;
use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEmployeeProfileRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = fn (string $field): string => in_array($field, EmployeeProfile::REQUIRED, true)
            ? 'required'
            : 'nullable';

        return [
            // The staff ID stays on users and stays optional: people are hired
            // before the number is issued, and it must not lock them out.
            'employee_id' => [
                'nullable', 'string', 'max:40',
                Rule::unique('users', 'employee_id')->ignore($this->user()->id),
            ],
            'attendance_id' => ['nullable', 'string', 'max:40'],

            'first_name' => [$required('first_name'), 'string', 'max:80'],
            'last_name' => [$required('last_name'), 'string', 'max:80'],
            'other_names' => ['nullable', 'string', 'max:80'],
            'title' => ['nullable', 'string', Rule::in(config('profile.titles'))],
            'gender' => [$required('gender'), 'string', Rule::in(config('profile.genders'))],
            // Nobody working here was born in the future, and 16 is the floor
            // the employment paperwork assumes.
            'date_of_birth' => [$required('date_of_birth'), 'date', 'before:'.now()->subYears(16)->toDateString()],
            'place_of_birth' => ['nullable', 'string', 'max:120'],
            'marital_status' => ['nullable', 'string', Rule::in(config('profile.marital_statuses'))],
            'mothers_maiden_name' => ['nullable', 'string', 'max:120'],

            'spouse_name' => ['nullable', 'string', 'max:120'],
            'spouse_phone' => ['nullable', 'string', 'max:30'],
            'number_of_kids' => ['nullable', 'integer', 'min:0', 'max:50'],

            'blood_group' => ['nullable', 'string', Rule::in(config('profile.blood_groups'))],
            'genotype' => ['nullable', 'string', Rule::in(config('profile.genotypes'))],
            'religion' => ['nullable', 'string', Rule::in(config('profile.religions'))],
            'allergies' => ['nullable', 'string', 'max:2000'],
            'medical_history' => ['nullable', 'string', 'max:2000'],
            'national_id_number' => ['nullable', 'string', 'max:40'],

            'country_of_origin' => [
                $required('country_of_origin'), 'string', 'size:2',
                Rule::in(Countries::codes()),
            ],
            'state_of_origin' => [$required('state_of_origin'), 'string', 'max:80'],
            'local_government' => ['nullable', 'string', 'max:80'],

            // The primary phone and the joining date land on users, where the
            // rest of the app already reads them. The email is not editable
            // here at all.
            'phone' => ['required', 'string', 'max:30'],
            // Nobody joined before the company existed or starts in the far
            // future; a start date a month out covers a notice period.
            'hired_at' => ['required', 'date', 'after:1990-01-01', 'before_or_equal:'.now()->addMonth()->toDateString()],
            'alternate_phone' => ['nullable', 'string', 'max:30'],
            'alternate_email' => ['nullable', 'string', 'email', 'max:190'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'employee_id' => 'staff ID',
            'attendance_id' => 'attendance ID',
            'hired_at' => 'date you joined',
            'country_of_origin' => 'country of origin',
            'state_of_origin' => 'state',
            'national_id_number' => 'national identification number',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_of_birth.before' => 'Enter a date of birth for someone at least 16 years old.',
            'hired_at.before_or_equal' => 'A joining date that far ahead is not one we can hold you to.',
            'employee_id.unique' => 'That staff ID already belongs to someone else.',
            'country_of_origin.in' => 'Pick a country from the list.',
        ];
    }
}
