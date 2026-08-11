<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Everything here is optional. Payroll chases missing bank details on its own
 * schedule, and a half-filled form must not stop someone clocking in.
 */
class UpdateBankDetailsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_name' => ['nullable', 'string', Rule::in(config('profile.banks'))],
            'account_number' => ['nullable', 'string', 'digits_between:10,20'],
            'account_name' => ['nullable', 'string', 'max:120'],
            'bvn' => ['nullable', 'digits:11'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'sort_code' => ['nullable', 'string', 'max:20'],
            'annual_rent' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],

            'rsa_number' => ['nullable', 'string', 'max:30'],
            'pfa_name' => ['nullable', 'string', Rule::in(config('profile.pension_administrators'))],

            'tax_identification_number' => ['nullable', 'string', 'max:30'],
            'nhf_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bvn' => 'BVN',
            'rsa_number' => 'RSA number',
            'pfa_name' => 'PFA',
            'tax_identification_number' => 'tax identification number',
            'nhf_number' => 'NHF number',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_number.digits_between' => 'An account number is 10 digits or more, numbers only.',
            'bvn.digits' => 'A BVN is exactly 11 digits.',
            'bank_name.in' => 'Pick a bank from the list.',
            'pfa_name.in' => 'Pick a pension administrator from the list.',
        ];
    }
}
