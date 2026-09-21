<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\PublicHoliday;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicHolidayRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManageLocations) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var PublicHoliday|null $holiday */
        $holiday = $this->route('holiday');

        return [
            'name' => ['required', 'string', 'max:120'],
            // One holiday a day: two names for the same day would count it
            // off twice in nobody's favour, and read as a mistake.
            'date' => [
                'required',
                'date',
                Rule::unique('public_holidays', 'date')->ignore($holiday?->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date.unique' => 'There is already a holiday on that day.',
        ];
    }
}
