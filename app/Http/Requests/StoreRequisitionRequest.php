<?php

namespace App\Http\Requests;

use App\Services\Paystack\AccountNotResolved;
use App\Services\Paystack\PaystackClient;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raising a requisition. Whoever is to be paid is named by bank and account
 * number; the account's name is what the bank holds, looked up through
 * Paystack here, never what the form says.
 */
class StoreRequisitionRequest extends FormRequest
{
    protected ?string $accountName = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:150'],
            'purpose' => ['required', 'string', 'min:10', 'max:4000'],
            'amount' => ['required', 'numeric', 'min:1', 'max:999999999999'],
            'bank_code' => ['required', 'string', Rule::in(array_column(app(PaystackClient::class)->banks(), 'code'))],
            'account_number' => ['required', 'digits:10'],
            // Quotes and invoices, where there are any. None is fine.
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
            'bank_code.required' => 'Pick the bank to pay into.',
            'bank_code.in' => 'Pick a bank from the list.',
            'account_number.digits' => 'An account number is 10 digits, numbers only.',
            'documents.*.max' => 'Each document can be up to 10 MB.',
            'documents.*.mimes' => 'Attach a PDF, an image, or a Word or Excel file.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['bank_code', 'account_number'])) {
                return;
            }

            try {
                $this->accountName = app(PaystackClient::class)->resolveAccount(
                    $this->string('account_number')->toString(),
                    $this->string('bank_code')->toString(),
                );
            } catch (AccountNotResolved $e) {
                $validator->errors()->add('account_number', $e->getMessage());
            }
        });
    }

    public function accountName(): string
    {
        return (string) $this->accountName;
    }
}
