<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Used for both opening and editing a period closed to leave.
 */
class LeaveRestrictedPeriodRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:255'],

            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],

            // The statuses that may book anyway, held to the same pick-list
            // the profile form writes from, so the two can never disagree.
            'exempt_marital_statuses' => ['array'],
            'exempt_marital_statuses.*' => [Rule::in(config('profile.marital_statuses'))],

            'exempt_leave_type_ids' => ['array'],
            'exempt_leave_type_ids.*' => [Rule::exists('leave_types', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'start_date' => 'first day',
            'end_date' => 'last day',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'end_date.after_or_equal' => 'A period cannot end before it starts.',
            'exempt_marital_statuses.*.in' => 'Pick marital statuses from the list.',
            'exempt_leave_type_ids.*.exists' => 'Pick leave types that exist.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'name' => $this->validated('name'),
            'reason' => $this->validated('reason'),
            'start_date' => $this->validated('start_date'),
            'end_date' => $this->validated('end_date'),
            'exempt_marital_statuses' => array_values($this->validated('exempt_marital_statuses', [])),
        ];
    }

    /**
     * Types the period leaves alone, ready for the pivot.
     *
     * @return array<int, int>
     */
    public function exemptLeaveTypeIds(): array
    {
        return array_map(intval(...), array_values($this->validated('exempt_leave_type_ids', [])));
    }
}
