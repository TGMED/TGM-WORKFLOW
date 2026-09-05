<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalaryProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManagePayroll) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Only people who actually appear on the payroll: administrators
            // run the system rather than draw a salary through it.
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('is_active', true),
            ],
            'annual_gross' => ['required', 'numeric', 'min:1', 'max:9999999999'],
            'pension_applies' => ['boolean'],
            'nhf_applies' => ['boolean'],
            'effective_from' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'annual_gross.min' => 'A salary has to be more than nothing.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'annual_gross' => (float) $this->input('annual_gross'),
            'pension_applies' => $this->boolean('pension_applies'),
            'nhf_applies' => $this->boolean('nhf_applies'),
            'effective_from' => $this->date('effective_from'),
        ];
    }
}
