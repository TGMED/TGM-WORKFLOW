<?php

namespace App\Http\Requests;

use App\Models\TerminationRecommendation;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTerminationRecommendationRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Only somebody with people answering to them can raise one at all.
        // Which of those people is checked below, where the message can say so.
        return ($this->user()?->peopleAnsweringToMe() ?? []) !== [];
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
            'offence_id' => [
                'nullable',
                'integer',
                Rule::exists('offences', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            'occurrence' => ['required', 'integer', 'between:1,20'],
            // Long enough to be a case rather than a complaint. Somebody's
            // livelihood turns on what is written here.
            'grounds' => ['required', 'string', 'min:40', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_user_id.not_in' => 'You cannot raise one about yourself.',
            'subject_user_id.exists' => 'Pick somebody still with the company.',
            'grounds.min' => 'Set out the case properly: what happened, when, and what was done about it already.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('subject_user_id')) {
                return;
            }

            /** @var User $raiser */
            $raiser = $this->user();
            $subjectId = (int) $this->input('subject_user_id');

            if (! in_array($subjectId, $raiser->peopleAnsweringToMe(), true)) {
                $validator->errors()->add(
                    'subject_user_id',
                    'You can only raise this about somebody who answers to you.',
                );

                return;
            }

            $open = TerminationRecommendation::query()
                ->open()
                ->where('subject_user_id', $subjectId)
                ->exists();

            if ($open) {
                $validator->errors()->add(
                    'subject_user_id',
                    'There is already one about them with HR.',
                );
            }
        });
    }
}
