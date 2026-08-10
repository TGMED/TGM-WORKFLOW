<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:80'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_meters' => ['required', 'integer', 'between:20,20000'],
            'max_accuracy_meters' => ['required', 'integer', 'between:10,5000'],
            'work_starts_at' => ['required', 'date_format:H:i'],
            'work_ends_at' => ['required', 'date_format:H:i', 'after:work_starts_at'],
            'grace_minutes' => ['required', 'integer', 'between:0,240'],
            // Zero switches breaks off for the site.
            'break_minutes' => ['required', 'integer', 'between:0,480'],
            'workdays' => ['required', 'array', 'min:1'],
            'workdays.*' => ['integer', 'between:1,7'],
            'timezone' => ['required', 'string', Rule::in(timezone_identifiers_list())],
            'accepts_signups' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'address.required' => 'Give the site a street address so staff recognise it.',
            'latitude.required' => 'Drop a pin on the map or enter coordinates for this site.',
            'work_ends_at.after' => 'The closing time must be later than the opening time.',
        ];
    }
}
