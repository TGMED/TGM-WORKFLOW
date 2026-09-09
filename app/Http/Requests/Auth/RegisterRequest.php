<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:30'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('is_active', true)],
            'position' => ['nullable', 'string', 'max:80'],

            // Optional at signup: a site may not exist yet when someone
            // registers. They cannot clock in until one is chosen, which the
            // dashboard prompts for.
            'location_id' => [
                'nullable',
                'integer',
                Rule::exists('locations', 'id')
                    ->where('is_active', true)
                    ->where('accepts_signups', true),
            ],

            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'location_id.exists' => 'That work location is not accepting signups.',
        ];
    }
}
