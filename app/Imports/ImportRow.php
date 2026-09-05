<?php

namespace App\Imports;

use Illuminate\Support\Carbon;

/**
 * One row of an uploaded file, addressed by header name.
 *
 * Spreadsheets are generous about what they put in a cell: a date can arrive
 * as `2026-01-31`, `31/01/2026` or Excel's own serial number, and a yes/no
 * as anything from `TRUE` to `Y`. The coercion lives here so every importer
 * reads the same file the same way, and so a person filling in a template is
 * never rejected over a formatting habit.
 */
final class ImportRow
{
    /**
     * @param  array<string, string>  $values  Keyed by normalised header.
     * @param  int  $number  The line in the file, counting the header as 1.
     */
    public function __construct(
        public readonly array $values,
        public readonly int $number,
    ) {}

    public function has(string $column): bool
    {
        return array_key_exists(CsvReader::normalise($column), $this->values);
    }

    /**
     * The raw cell, trimmed. Null when the column is absent or empty, so a
     * blank cell and a missing column read the same way: nothing was said.
     */
    public function raw(string $column): ?string
    {
        $value = trim($this->values[CsvReader::normalise($column)] ?? '');

        return $value === '' ? null : $value;
    }

    public function string(string $column): ?string
    {
        return $this->raw($column);
    }

    public function integer(string $column): ?int
    {
        $value = $this->raw($column);

        if ($value === null) {
            return null;
        }

        // Thousands separators survive a round trip through most
        // spreadsheets, and are not the operator's mistake to fix.
        $value = str_replace([',', ' '], '', $value);

        return is_numeric($value) ? (int) $value : null;
    }

    public function float(string $column): ?float
    {
        $value = $this->raw($column);

        if ($value === null) {
            return null;
        }

        $value = str_replace([',', ' ', '₦'], '', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * Null when the column is absent or blank, so an importer can tell "leave
     * this as it is" from "set it to no".
     */
    public function boolean(string $column, ?bool $default = null): ?bool
    {
        $value = $this->raw($column);

        if ($value === null) {
            return $default;
        }

        return match (mb_strtolower($value)) {
            'yes', 'y', 'true', '1', 'on', 'active' => true,
            'no', 'n', 'false', '0', 'off', 'inactive' => false,
            default => $default,
        };
    }

    /**
     * A date, whatever shape the spreadsheet handed it over in. Anything
     * unparseable comes back null and the importer's own rules reject it,
     * which keeps the error on the row rather than in a stack trace.
     */
    public function date(string $column): ?Carbon
    {
        $value = $this->raw($column);

        if ($value === null) {
            return null;
        }

        // Excel writes a bare serial number when a cell is formatted as a
        // date and exported without one. 25569 is 1970-01-01 on its clock.
        if (preg_match('/^\d{5}$/', $value) === 1) {
            return Carbon::createFromTimestampUTC(((int) $value - 25569) * 86400)->startOfDay();
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'd M Y', 'j F Y'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);
            } catch (\Throwable) {
                continue;
            }

            // Carbon is forgiving about a format a value only half matches,
            // so the result is written back out and compared. Otherwise
            // 13/01/2026 would slip through the American format as a date in
            // some other month.
            if ($parsed !== null && $parsed->format($format) === $value) {
                return $parsed->startOfDay();
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A date and time. Falls back to the start of the day when only a date
     * was given, which is what a clock-in column full of dates means.
     */
    public function dateTime(string $column): ?Carbon
    {
        $value = $this->raw($column);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return $this->date($column);
        }
    }

    /**
     * A cell holding several values, separated by a semicolon or a pipe. A
     * comma is deliberately not a separator: it is the field delimiter, and
     * a cell that uses one has to be quoted, which people forget.
     *
     * @return array<int, string>
     */
    public function list(string $column): array
    {
        $value = $this->raw($column);

        if ($value === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split('/[;|]/', $value) ?: []),
            fn (string $part): bool => $part !== '',
        ));
    }

    /**
     * The row as the failure list shows it, for finding the line again.
     *
     * @return array<string, string>
     */
    public function preview(): array
    {
        return array_filter($this->values, fn (string $value): bool => trim($value) !== '');
    }
}
