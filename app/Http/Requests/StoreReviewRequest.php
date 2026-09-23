<?php

namespace App\Http\Requests;

use App\Enums\ReviewVisibility;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Anyone signed in may say something about a colleague's work. How far
        // it travels is settled by where they stand, not by whether they may.
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'subject_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at'),
                Rule::notIn([$this->user()?->id]),
            ],
            'visibility' => ['required', Rule::enum(ReviewVisibility::class)],
            'rating' => ['required', 'integer', 'between:1,5'],
            'body' => ['required', 'string', 'min:20', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_user_id.not_in' => 'You cannot review yourself.',
            'subject_user_id.exists' => 'Pick somebody still with the company.',
            'body.min' => 'Say a little more: what they did, and how it went.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('subject_user_id')) {
                return;
            }

            // Administrators run the system rather than work in it, so there
            // is no work of theirs here to review.
            $clocksIn = User::query()
                ->whereKey($this->integer('subject_user_id'))
                ->clocksIn()
                ->exists();

            if (! $clocksIn) {
                $validator->errors()->add('subject_user_id', 'Pick a member of staff.');
            }
        });
    }
}
