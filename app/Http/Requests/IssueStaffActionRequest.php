<?php

namespace App\Http\Requests;

use App\Enums\EmploymentStatus;
use App\Enums\Permission;
use App\Enums\StaffActionKind;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IssueStaffActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::IssueConduct) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $kind = StaffActionKind::tryFrom((string) $this->input('kind'));

        return [
            'subject_user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at'),
                Rule::notIn([$this->user()?->id]),
            ],
            'kind' => ['required', Rule::enum(StaffActionKind::class)],
            // A confirmation letter has an obvious title; the others need one.
            'title' => [$kind === StaffActionKind::Confirmation ? 'nullable' : 'required', 'string', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:5000'],
            'offence_id' => [
                'nullable',
                'integer',
                Rule::prohibitedIf($kind === StaffActionKind::Confirmation),
                Rule::exists('offences', 'id')->whereNull('deleted_at'),
            ],
            'response_due_on' => [
                Rule::requiredIf($kind === StaffActionKind::Query),
                Rule::prohibitedIf($kind !== null && $kind !== StaffActionKind::Query),
                'nullable',
                'date',
                'after_or_equal:today',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'subject_user_id.not_in' => 'You cannot issue one to yourself.',
            'subject_user_id.exists' => 'Pick somebody still with the company.',
            'response_due_on.required' => 'Give the date the answer is due by.',
            'response_due_on.after_or_equal' => 'The answer cannot be due before today.',
            'offence_id.prohibited' => 'A confirmation does not rest on an offence.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['subject_user_id', 'kind'])) {
                return;
            }

            $subject = User::query()->find($this->integer('subject_user_id'));

            if ($subject === null || ! $subject->clocksIn()) {
                $validator->errors()->add('subject_user_id', 'Pick a member of staff.');

                return;
            }

            if ($this->input('kind') === StaffActionKind::Confirmation->value
                && $subject->employment_status !== EmploymentStatus::Probation) {
                $validator->errors()->add('subject_user_id', "{$subject->name} is already confirmed.");
            }
        });
    }
}
