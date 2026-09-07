<?php

namespace App\Imports\Importers;

use App\Enums\ImportDuplicates;
use App\Enums\LeaveAnchor;
use App\Enums\Permission;
use App\Imports\BaseImporter;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Models\LeaveType;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * The kinds of leave staff may ask for, and the policy rules attached to
 * each. Runs before any historical leave is loaded, since a leave record
 * names its type by slug.
 */
class LeaveTypeImporter extends BaseImporter
{
    public function key(): string
    {
        return 'leave-types';
    }

    public function label(): string
    {
        return 'Leave types';
    }

    public function description(): string
    {
        return 'The kinds of leave staff may take, with the allowance and eligibility rules the HR policy attaches to each.';
    }

    public function permission(): Permission
    {
        return Permission::ManageRequestSettings;
    }

    public function matchedOn(): string
    {
        return 'the leave type slug';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'Allowances are counted in working days at the person\'s own site, so a weekend inside a booking is never deducted.',
            'Leave days_per_year blank to ship the type uncapped: it is available without a yearly allowance being counted against it.',
            'A slug is only worked out from the name when the type is new. Renaming an existing type leaves its slug alone, so nothing already keyed on it is orphaned.',
            'The anchor and window columns travel together. A window with no anchor, or an anchor with no window, would silently never apply and the row is rejected.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        return [
            ImportColumn::make(
                'slug',
                'The type\'s short name, which leave records point at. Left blank on a new type it is worked out from the name.',
                'compassionate',
                format: 'Lowercase letters, numbers and hyphens',
            ),
            ImportColumn::required('name', 'What the type is called on the leave form.', 'Compassionate leave'),
            ImportColumn::make('description', 'A sentence saying when this type applies.', 'Leave following a bereavement or family emergency.'),
            ImportColumn::make('days_per_year', 'The yearly allowance in working days. Blank means uncapped.', '10', format: 'Whole number, 1 to 365'),
            ImportColumn::make('days_per_year_manager', 'The separate allowance for managers and above. Blank means one allowance for everyone.', '15', format: 'Whole number, 1 to 365'),
            ImportColumn::make('min_service_months', 'Months of service before the type opens up, counted from the hire date.', '12', format: 'Whole number, 0 to 120'),
            ImportColumn::boolean('requires_confirmed', 'Whether the type is closed to staff still on probation.', default: false),
            ImportColumn::boolean('requires_evidence', 'Whether a document has to be attached to the request.', default: false),
            ImportColumn::make(
                'anchor',
                'The date on the employee record an entitlement hangs off, for leave that lapses if it is not taken.',
                'birthday',
                format: 'birthday or hire_date',
                accepts: array_column(LeaveAnchor::cases(), 'value'),
            ),
            ImportColumn::make('window_months', 'How long after the anchor date the entitlement stays claimable.', '6', format: 'Whole number, 1 to 24'),
            ImportColumn::boolean('is_paid', 'Whether time off of this kind is paid.'),
            ImportColumn::boolean('is_active', 'Whether staff may still choose this type on the leave form.'),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $name = $row->string('name');
        $slug = $row->string('slug');

        $existing = $slug === null
            ? null
            : LeaveType::query()->where('slug', $slug)->first();

        if ($existing !== null && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($existing !== null && $duplicates === ImportDuplicates::Reject) {
            $this->reject("There is already a leave type with the slug {$slug}.");
        }

        if ($existing === null && $slug !== null) {
            $this->reject("No leave type has the slug {$slug}. Leave the slug blank to create a new type from the name.");
        }

        $data = $this->validate(
            [
                'name' => $name,
                'description' => $row->string('description'),
                'days_per_year' => $row->integer('days_per_year'),
                'days_per_year_manager' => $row->integer('days_per_year_manager'),
                'min_service_months' => $row->integer('min_service_months'),
                'requires_confirmed' => $row->boolean('requires_confirmed'),
                'requires_evidence' => $row->boolean('requires_evidence'),
                'anchor' => $row->string('anchor'),
                'window_months' => $row->integer('window_months'),
                'is_paid' => $row->boolean('is_paid'),
                'is_active' => $row->boolean('is_active'),
            ],
            [
                'name' => ['required', 'string', 'max:80', Rule::unique('leave_types', 'name')->ignore($existing?->id)],
                'description' => ['nullable', 'string', 'max:255'],
                'days_per_year' => ['nullable', 'integer', 'between:1,365'],
                'days_per_year_manager' => ['nullable', 'integer', 'between:1,365'],
                'min_service_months' => ['nullable', 'integer', 'between:0,120'],
                'requires_confirmed' => ['nullable', 'boolean'],
                'requires_evidence' => ['nullable', 'boolean'],
                'anchor' => ['nullable', Rule::enum(LeaveAnchor::class), 'required_with:window_months'],
                'window_months' => ['nullable', 'integer', 'between:1,24', 'required_with:anchor'],
                'is_paid' => ['nullable', 'boolean'],
                'is_active' => ['nullable', 'boolean'],
            ],
            [
                'name.unique' => 'There is already a leave type with that name.',
                'anchor.required_with' => 'Say which date the window is counted from.',
                'window_months.required_with' => 'Say how many months the entitlement stays claimable for.',
            ],
        );

        if ($existing !== null) {
            $existing->update($this->present($data));
            $result->updated();

            return;
        }

        LeaveType::query()->create([
            ...$this->present($data),
            'slug' => $this->uniqueSlug(Str::slug((string) $data['name'])),
        ]);

        $result->created();
    }

    /**
     * The slug is derived once, on creation, and then left alone so renaming
     * a type does not orphan anything keyed on it.
     */
    private function uniqueSlug(string $base): string
    {
        $slug = $base === '' ? 'leave-type' : $base;
        $candidate = $slug;
        $suffix = 2;

        while (LeaveType::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$slug}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }
}
