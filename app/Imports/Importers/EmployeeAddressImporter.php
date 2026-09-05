<?php

namespace App\Imports\Importers;

use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Imports\BaseImporter;
use App\Imports\Concerns\ResolvesStaff;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Models\EmployeeAddress;
use App\Support\Countries;
use Illuminate\Validation\Rule;

/**
 * Addresses on file. One row per address, so somebody with a residential and
 * a permanent address takes two rows naming the same person.
 */
class EmployeeAddressImporter extends BaseImporter
{
    use ResolvesStaff;

    public function key(): string
    {
        return 'employee-addresses';
    }

    public function label(): string
    {
        return 'Employee addresses';
    }

    public function description(): string
    {
        return 'The addresses each person keeps on file — residential, permanent, postal.';
    }

    public function permission(): Permission
    {
        return Permission::ManageStaff;
    }

    /**
     * @return array<int, string>
     */
    public function dependsOn(): array
    {
        return ['staff'];
    }

    public function matchedOn(): string
    {
        return 'the person together with the address label, so each person has at most one of each kind';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'One row per address. Somebody with a residential and a permanent address takes two rows naming the same person.',
            'Country is a two-letter ISO code, not a country name.',
            'Labels accepted are: '.implode(', ', (array) config('profile.address_types')).'.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        /** @var array<int, string> $labels */
        $labels = (array) config('profile.address_types');

        return [
            ...$this->staffColumns(),

            ImportColumn::required('label', 'What kind of address this is. A person has at most one of each.', 'Residential', accepts: $labels),
            ImportColumn::required('street', 'House number and street.', '14 Ogunlana Drive'),
            ImportColumn::make('city', 'Town or city.', 'Surulere'),
            ImportColumn::make('state', 'State or province.', 'Lagos State'),
            ImportColumn::make('country', 'Two-letter ISO country code.', 'NG', format: 'Two-letter ISO country code'),
            ImportColumn::make('postal_code', 'Postcode, where the country uses them.', '101283'),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $user = $this->staffFor($row);
        $label = $row->string('label');

        $existing = $label === null
            ? null
            : EmployeeAddress::query()
                ->where('user_id', $user->id)
                ->where('label', $label)
                ->first();

        if ($existing !== null && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($existing !== null && $duplicates === ImportDuplicates::Reject) {
            $this->reject("{$user->name} already has a {$label} address on file.");
        }

        $country = $row->string('country');

        $data = $this->validate(
            [
                'label' => $label,
                'street' => $row->string('street'),
                'city' => $row->string('city'),
                'state' => $row->string('state'),
                'country' => $country === null ? null : mb_strtoupper($country),
                'postal_code' => $row->string('postal_code'),
            ],
            [
                'label' => ['required', 'string', Rule::in((array) config('profile.address_types'))],
                'street' => [$existing === null ? 'required' : 'nullable', 'string', 'max:180'],
                'city' => ['nullable', 'string', 'max:80'],
                'state' => ['nullable', 'string', 'max:80'],
                'country' => ['nullable', 'string', 'size:2', Rule::in(Countries::codes())],
                'postal_code' => ['nullable', 'string', 'max:20'],
            ],
            [
                'label.in' => 'That is not an address type the profile offers.',
                'country.in' => 'That is not a country code. Use the two-letter ISO code, such as NG.',
            ],
        );

        if ($existing !== null) {
            $existing->update($this->present($data));
            $result->updated();

            return;
        }

        $user->addresses()->create($this->present($data));
        $result->created();
    }
}
