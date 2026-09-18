<?php

namespace App\Http\Requests;

use App\Enums\ReportStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->canHandleReports() === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ReportStatus::class)],
            // What the case was found to be, off the register. Optional: not
            // every upheld report is a named offence, and a case can be closed
            // as unfounded without naming one at all.
            'offence_id' => [
                'nullable',
                'integer',
                Rule::exists('offences', 'id')->where('is_active', true)->whereNull('deleted_at'),
            ],
            // Closing a case asks for a reason. An open one may be picked up
            // without writing anything yet.
            'resolution_note' => [
                Rule::requiredIf(fn (): bool => ! $this->closingStatus()?->isOpen()),
                'nullable',
                'string',
                'max:2000',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'resolution_note.required' => 'Say what was done before closing this.',
            'offence_id.exists' => 'Pick an offence that is still on the register.',
        ];
    }

    protected function closingStatus(): ?ReportStatus
    {
        return ReportStatus::tryFrom($this->string('status')->toString());
    }
}
