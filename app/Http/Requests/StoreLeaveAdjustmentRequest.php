<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StoreLeaveAdjustmentRequest extends FormRequest
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
        $earliest = Carbon::now()->year - 1;
        $latest = Carbon::now()->year + 1;

        return [
            // An uncapped type enforces no balance, so there is nothing for an
            // adjustment to move.
            'leave_type_id' => [
                'required',
                'integer',
                Rule::exists('leave_types', 'id')
                    ->where('is_active', true)
                    ->whereNotNull('days_per_year'),
            ],
            // Last year stays open a while for carry-over worked out after the
            // fact; next year can be set up in advance.
            'year' => ['required', 'integer', "between:{$earliest},{$latest}"],
            'days' => ['required', 'integer', 'between:-365,365', 'not_in:0'],
            'reason' => ['required', 'string', 'max:120'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'leave_type_id.exists' => 'Pick a leave type that has a yearly allowance.',
            'days.not_in' => 'An adjustment of no days would change nothing.',
            'reason.required' => 'Say why the balance is being changed.',
        ];
    }
}
