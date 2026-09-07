<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PayrollSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManagePayroll) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'currency' => ['required', 'string', 'size:3'],

            'basic_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'housing_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'transport_percent' => ['required', 'numeric', 'min:0', 'max:100'],

            'pension_employee_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'pension_employer_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'nhf_percent' => ['required', 'numeric', 'min:0', 'max:100'],

            'rent_relief_percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'rent_relief_cap' => ['required', 'numeric', 'min:0'],

            'tax_bands' => ['required', 'array', 'min:1'],
            'tax_bands.*.up_to' => ['nullable', 'numeric', 'min:1'],
            'tax_bands.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $split = (float) $this->input('basic_percent')
                + (float) $this->input('housing_percent')
                + (float) $this->input('transport_percent');

            if ($split > 100) {
                $validator->errors()->add(
                    'basic_percent',
                    'Basic, housing and transport come to '.round($split, 2).'% of the package, which is more than all of it.',
                );
            }

            $this->guardAgainstAnUnboundedTable($validator);
        });
    }

    /**
     * The bands have to cover every income, so exactly one of them must be
     * open-ended. Without it a high earner falls off the end of the table and
     * is silently undertaxed.
     */
    protected function guardAgainstAnUnboundedTable(Validator $validator): void
    {
        $open = array_filter(
            $this->input('tax_bands', []),
            fn (array $band): bool => ($band['up_to'] ?? null) === null || $band['up_to'] === '',
        );

        if (count($open) !== 1) {
            $validator->errors()->add(
                'tax_bands',
                'Exactly one band has to be left open-ended, to catch everything above the band below it.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $bands = array_map(fn (array $band): array => [
            'up_to' => ($band['up_to'] ?? null) === null || $band['up_to'] === ''
                ? null
                : (float) $band['up_to'],
            'rate' => (float) $band['rate'],
        ], $this->input('tax_bands', []));

        return [
            'currency' => strtoupper($this->string('currency')->toString()),
            'basic_percent' => (float) $this->input('basic_percent'),
            'housing_percent' => (float) $this->input('housing_percent'),
            'transport_percent' => (float) $this->input('transport_percent'),
            'pension_employee_percent' => (float) $this->input('pension_employee_percent'),
            'pension_employer_percent' => (float) $this->input('pension_employer_percent'),
            'nhf_percent' => (float) $this->input('nhf_percent'),
            'rent_relief_percent' => (float) $this->input('rent_relief_percent'),
            'rent_relief_cap' => (float) $this->input('rent_relief_cap'),
            'tax_bands' => $bands,
        ];
    }
}
