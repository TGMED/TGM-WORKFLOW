<?php

namespace App\Http\Requests;

use App\Services\Paystack\AccountNotResolved;
use App\Services\Paystack\PaystackClient;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Everything here is optional. Payroll chases missing bank details on its own
 * schedule, and a half-filled form must not stop someone clocking in.
 *
 * The account name is never taken from the form. Where a bank and a number are
 * both given, the name is whatever the bank holds against that account, looked
 * up through Paystack, so salary is never sent to a name somebody misspelt.
 */
class UpdateBankDetailsRequest extends FormRequest
{
    /** What Paystack said the account is called, once validation has asked. */
    protected ?string $resolvedAccountName = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $codes = array_column(app(PaystackClient::class)->banks(), 'code');

        return [
            'bank_code' => ['nullable', 'string', Rule::in($codes)],
            // A NUBAN, which is the only kind of number a Nigerian bank issues
            // and the only kind Paystack can look up.
            'account_number' => ['nullable', 'digits:10', 'required_with:bank_code'],
            'bvn' => ['nullable', 'digits:11'],
            'swift_code' => ['nullable', 'string', 'max:20'],
            'sort_code' => ['nullable', 'string', 'max:20'],
            'annual_rent' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],

            'rsa_number' => ['nullable', 'string', 'max:30'],
            'pfa_name' => ['nullable', 'string', Rule::in(config('profile.pension_administrators'))],

            'tax_identification_number' => ['nullable', 'string', 'max:30'],
            'nhf_number' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'bank_code' => 'bank',
            'bvn' => 'BVN',
            'rsa_number' => 'RSA number',
            'pfa_name' => 'PFA',
            'tax_identification_number' => 'tax identification number',
            'nhf_number' => 'NHF number',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'account_number.digits' => 'An account number is 10 digits, numbers only.',
            'account_number.required_with' => 'Give the account number at this bank.',
            'bvn.digits' => 'A BVN is exactly 11 digits.',
            'bank_code.in' => 'Pick a bank from the list.',
            'pfa_name.in' => 'Pick a pension administrator from the list.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->hasAny(['bank_code', 'account_number'])) {
                return;
            }

            $code = $this->string('bank_code')->toString();
            $number = $this->string('account_number')->toString();

            if ($number !== '' && $code === '') {
                $validator->errors()->add('bank_code', 'Pick the bank this account is with.');

                return;
            }

            if ($number === '') {
                return;
            }

            // The same account as last time, already looked up: no need to ask
            // Paystack again just because somebody changed their TIN.
            $profile = $this->user()?->profile;

            if ($profile !== null
                && $profile->bank_code === $code
                && $profile->account_number === $number
                && $profile->account_name !== null) {
                $this->resolvedAccountName = $profile->account_name;

                return;
            }

            try {
                $this->resolvedAccountName = app(PaystackClient::class)->resolveAccount($number, $code);
            } catch (AccountNotResolved $e) {
                $validator->errors()->add('account_number', $e->getMessage());
            }
        });
    }

    /**
     * What goes onto the profile row: the form as validated, with the bank's
     * name and the account's name filled in from Paystack.
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $code = $this->validated('bank_code');

        return [
            ...$this->validated(),
            'bank_name' => $code === null ? null : app(PaystackClient::class)->bankName($code),
            'account_name' => $this->resolvedAccountName,
        ];
    }
}
