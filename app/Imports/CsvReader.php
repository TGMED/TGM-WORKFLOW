<?php

namespace App\Imports;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Turns an uploaded CSV into rows keyed by header name.
 *
 * Read line by line rather than into an array, so a file with ten thousand
 * rows costs the same memory as one with ten.
 */
final class CsvReader
{
    /**
     * A guard rail, not a limit anyone should meet: it stops a mistaken
     * upload from tying up a request for minutes.
     */
    public const MAX_ROWS = 5000;

    /**
     * Headers are matched loosely, so `Employee ID`, `employee_id` and
     * `EMPLOYEE-ID` are the same column. People rename these by hand and a
     * template is only useful if it survives being tidied up.
     */
    public static function normalise(string $header): string
    {
        // Strip a UTF-8 byte order mark, which Excel writes onto the first
        // header and which would otherwise make column one unmatchable.
        $header = preg_replace('/^\x{FEFF}/u', '', $header) ?? $header;

        $header = mb_strtolower(trim($header));

        return preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;
    }

    /**
     * The file's header row, normalised and in file order.
     *
     * @return array<int, string>
     */
    public function headers(UploadedFile $file): array
    {
        $handle = $this->open($file);

        try {
            $headers = fgetcsv($handle);
        } finally {
            fclose($handle);
        }

        if ($headers === false) {
            return [];
        }

        return array_map(
            fn ($header): string => self::normalise((string) $header),
            $headers,
        );
    }

    /**
     * Every data row in the file, in order, as ImportRow objects.
     *
     * @return \Generator<int, ImportRow>
     */
    public function rows(UploadedFile $file): \Generator
    {
        $handle = $this->open($file);

        try {
            $headers = fgetcsv($handle);

            if ($headers === false) {
                return;
            }

            $headers = array_map(
                fn ($header): string => self::normalise((string) $header),
                $headers,
            );

            // The header is line 1, so the first data row is line 2 and the
            // numbers in the failure list match what the spreadsheet shows.
            $number = 1;
            $emitted = 0;

            while (($cells = fgetcsv($handle)) !== false) {
                $number++;

                // A trailing newline, or a row of empty cells left behind by
                // deleting content in a spreadsheet, is not a row.
                if ($this->isBlank($cells)) {
                    continue;
                }

                if ($emitted >= self::MAX_ROWS) {
                    throw new RuntimeException(
                        'That file has more than '.number_format(self::MAX_ROWS).' rows. Split it and import the parts one after another.',
                    );
                }

                $emitted++;

                yield new ImportRow($this->combine($headers, $cells), $number);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Pads or trims the row to the header width, so a short line loses a
     * value rather than the whole file.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, string|null>  $cells
     * @return array<string, string>
     */
    private function combine(array $headers, array $cells): array
    {
        $values = [];

        foreach ($headers as $index => $header) {
            if ($header === '') {
                continue;
            }

            $values[$header] = trim((string) ($cells[$index] ?? ''));
        }

        return $values;
    }

    /**
     * @param  array<int, string|null>  $cells
     */
    private function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if (trim((string) $cell) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return resource
     */
    private function open(UploadedFile $file)
    {
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            throw new RuntimeException('That file could not be opened.');
        }

        return $handle;
    }
}
