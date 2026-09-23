<?php

namespace App\Http\Requests;

use App\Services\Paystack\PaystackClient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ResolveBankAccountRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'bank_code' => ['required', 'string', Rule::in(array_column(app(PaystackClient::class)->banks(), 'code'))],
            'account_number' => ['required', 'digits:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'bank_code.in' => 'Pick a bank from the list.',
            'account_number.digits' => 'An account number is 10 digits, numbers only.',
        ];
    }
}
