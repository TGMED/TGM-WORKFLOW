<?php

namespace App\Http\Requests;

use App\Enums\RelationKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used for both adding and editing a relation: the three lists take the same
 * fields, so they take the same rules.
 */
class StoreEmployeeRelationRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kind = $this->enum('kind', RelationKind::class);

        return [
            'kind' => ['required', Rule::enum(RelationKind::class)],
            'name' => ['required', 'string', 'max:120'],
            'relationship' => ['required', 'string', Rule::in(config('profile.relationships'))],
            // Next of kin is the number someone rings in an emergency, so it
            // is the one list where a phone number is not optional.
            'phone' => [
                Rule::requiredIf($kind === RelationKind::NextOfKin),
                'nullable', 'string', 'max:30',
            ],
            'email' => ['nullable', 'string', 'email', 'max:190'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'gender' => ['nullable', 'string', Rule::in(config('profile.genders'))],
            'occupation' => ['nullable', 'string', 'max:120'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.required' => 'A next of kin needs a phone number someone can call.',
            'relationship.in' => 'Pick a relationship from the list.',
            'date_of_birth.before_or_equal' => 'A date of birth cannot be in the future.',
        ];
    }
}
