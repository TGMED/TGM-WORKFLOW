<?php

namespace App\Http\Requests;

use App\Enums\ExitReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Walking somebody out. The reason and the last day are both required: an
 * exit with neither is the bare deactivation this flow exists to replace.
 */
class StaffExitRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'exit_reason' => ['required', Rule::enum(ExitReason::class)],
            // A last day can sit in the future for somebody serving notice,
            // but not so far out that it is obviously a typed-in year.
            'exit_date' => ['required', 'date', 'before_or_equal:'.Carbon::now()->addYear()->toDateString()],
            'exit_note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'exit_reason.required' => 'Say why they are leaving.',
            'exit_date.required' => 'Give their last working day.',
            'exit_date.before_or_equal' => 'That last working day is too far ahead to be right.',
            'exit_note.max' => 'Keep the note under 1000 characters.',
        ];
    }

    public function reason(): ExitReason
    {
        return ExitReason::from($this->string('exit_reason')->toString());
    }

    public function lastWorkingDay(): Carbon
    {
        return Carbon::parse($this->string('exit_date')->toString())->startOfDay();
    }

    public function note(): ?string
    {
        $note = trim($this->string('exit_note')->toString());

        return $note === '' ? null : $note;
    }
}
