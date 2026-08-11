<?php

namespace App\Http\Requests;

use App\Support\Countries;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used for both adding and editing an address.
 */
class StoreEmployeeAddressRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', Rule::in(config('profile.address_types'))],
            'street' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'size:2', Rule::in(Countries::codes())],
            'postal_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'label.in' => 'Pick what kind of address this is.',
            'street.required' => 'Enter the street address.',
            'country.in' => 'Pick a country from the list.',
        ];
    }
}
