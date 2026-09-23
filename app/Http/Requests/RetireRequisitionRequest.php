<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetireRequisitionRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount_spent' => ['required', 'numeric', 'min:0', 'max:999999999999'],
            'notes' => ['nullable', 'string', 'max:4000'],
            // Receipts, where there are any. None is fine.
            'documents' => ['nullable', 'array', 'max:10'],
            'documents.*' => ['file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx,xls,xlsx'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'documents.*.max' => 'Each document can be up to 10 MB.',
            'documents.*.mimes' => 'Attach a PDF, an image, or a Word or Excel file.',
        ];
    }
}
