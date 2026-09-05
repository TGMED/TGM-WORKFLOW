<?php

namespace App\Imports\Importers;

use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Imports\BaseImporter;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Models\Location;
use Illuminate\Validation\Rule;

/**
 * The sites staff clock in at. First in the running order: staff, and every
 * attendance row after them, point at one of these.
 */
class LocationImporter extends BaseImporter
{
    public function key(): string
    {
        return 'locations';
    }

    public function label(): string
    {
        return 'Sites';
    }

    public function description(): string
    {
        return 'The places staff clock in at, with the geofence and working day each one is judged against.';
    }

    public function permission(): Permission
    {
        return Permission::ManageLocations;
    }

    public function matchedOn(): string
    {
        return 'the site name, case-insensitively';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'A site with no coordinates cannot take a clock-in: staff assigned to it will be turned away until a pin is dropped on the sites page.',
            'The working day is local to the site. A site in another timezone judges lateness against its own clock, not the company default.',
            'Workdays are ISO day numbers, 1 for Monday through 7 for Sunday. Leave the column blank for a Monday-to-Friday site.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        return [
            ImportColumn::required('name', 'What the site is called. This is what a row is matched on, so keep it stable between imports.', 'TGM Ikeja'),
            ImportColumn::required('address', 'Street address, so staff recognise the site in a list.', '12 Allen Avenue, Ikeja'),
            ImportColumn::make('city', 'The city, shown beside the name wherever sites are listed.', 'Lagos'),
            ImportColumn::make('latitude', 'Centre of the geofence, in decimal degrees.', '6.6018', format: 'Decimal, -90 to 90'),
            ImportColumn::make('longitude', 'Centre of the geofence, in decimal degrees.', '3.3515', format: 'Decimal, -180 to 180'),
            ImportColumn::make('radius_meters', 'How far from the pin a clock-in is still accepted.', '150', format: 'Whole number, 20 to 20000'),
            ImportColumn::make('max_accuracy_meters', 'How vague a phone\'s fix may be before it is refused.', '200', format: 'Whole number, 10 to 5000'),
            ImportColumn::make('work_starts_at', 'When the working day opens at this site.', '09:00', format: '24-hour HH:MM'),
            ImportColumn::make('work_ends_at', 'When the working day closes. Must be later than the opening time.', '17:00', format: '24-hour HH:MM'),
            ImportColumn::make('grace_minutes', 'How long after the opening time an arrival still counts as on time.', '10', format: 'Whole number, 0 to 240'),
            ImportColumn::make('break_minutes', 'How long one break may last here. Zero switches breaks off for the site.', '60', format: 'Whole number, 0 to 480'),
            ImportColumn::make('workdays', 'The days this site works, as ISO day numbers separated by semicolons.', '1;2;3;4;5', format: 'Day numbers 1-7'),
            ImportColumn::make('timezone', 'The site\'s own timezone, which its working day is read in.', 'Africa/Lagos', format: 'IANA timezone name'),
            ImportColumn::boolean('is_active', 'Whether the site is in use.'),
            ImportColumn::boolean('accepts_signups', 'Whether somebody signing up may choose this site.'),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $name = $row->string('name');

        $existing = $name === null
            ? null
            : Location::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($existing !== null && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($existing !== null && $duplicates === ImportDuplicates::Reject) {
            $this->reject("There is already a site called {$existing->name}.");
        }

        $data = $this->validate(
            $this->values($row),
            [
                'name' => ['required', 'string', 'max:120', Rule::unique('locations', 'name')->ignore($existing?->id)],
                'address' => [$existing === null ? 'required' : 'nullable', 'string', 'max:255'],
                'city' => ['nullable', 'string', 'max:80'],
                'latitude' => ['nullable', 'numeric', 'between:-90,90'],
                'longitude' => ['nullable', 'numeric', 'between:-180,180'],
                'radius_meters' => ['nullable', 'integer', 'between:20,20000'],
                'max_accuracy_meters' => ['nullable', 'integer', 'between:10,5000'],
                'work_starts_at' => ['nullable', 'date_format:H:i'],
                'work_ends_at' => ['nullable', 'date_format:H:i'],
                'grace_minutes' => ['nullable', 'integer', 'between:0,240'],
                'break_minutes' => ['nullable', 'integer', 'between:0,480'],
                'workdays' => ['nullable', 'array', 'min:1'],
                'workdays.*' => ['integer', 'between:1,7'],
                'timezone' => ['nullable', 'string', Rule::in(timezone_identifiers_list())],
                'is_active' => ['nullable', 'boolean'],
                'accepts_signups' => ['nullable', 'boolean'],
            ],
            [
                'name.unique' => 'Another site already has that name.',
                'timezone.in' => 'That is not a timezone name. Use one like Africa/Lagos.',
                'work_starts_at.date_format' => 'Write the opening time as HH:MM on a 24-hour clock.',
                'work_ends_at.date_format' => 'Write the closing time as HH:MM on a 24-hour clock.',
            ],
        );

        // Checked here rather than with an `after` rule so it also fires when
        // only one of the two times is in the file and the other is already
        // on the record.
        $starts = $data['work_starts_at'] ?? $existing?->work_starts_at;
        $ends = $data['work_ends_at'] ?? $existing?->work_ends_at;

        if ($starts !== null && $ends !== null && substr((string) $ends, 0, 5) <= substr((string) $starts, 0, 5)) {
            $this->reject('The closing time must be later than the opening time.');
        }

        if ($existing !== null) {
            $existing->update($this->present($data));
            $result->updated();

            return;
        }

        Location::query()->create($this->present($data));
        $result->created();
    }

    /**
     * @return array<string, mixed>
     */
    private function values(ImportRow $row): array
    {
        $workdays = array_map(intval(...), $row->list('workdays'));

        return [
            'name' => $row->string('name'),
            'address' => $row->string('address'),
            'city' => $row->string('city'),
            'latitude' => $row->float('latitude'),
            'longitude' => $row->float('longitude'),
            'radius_meters' => $row->integer('radius_meters'),
            'max_accuracy_meters' => $row->integer('max_accuracy_meters'),
            'work_starts_at' => $row->string('work_starts_at'),
            'work_ends_at' => $row->string('work_ends_at'),
            'grace_minutes' => $row->integer('grace_minutes'),
            'break_minutes' => $row->integer('break_minutes'),
            'workdays' => $workdays === [] ? null : $workdays,
            'timezone' => $row->string('timezone'),
            'is_active' => $row->boolean('is_active'),
            'accepts_signups' => $row->boolean('accepts_signups'),
        ];
    }
}
