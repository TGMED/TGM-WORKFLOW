<?php

namespace App\Imports;

use App\Imports\Contracts\Importer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The two files that go with every import: the template somebody fills in,
 * and the reference that says what each column means.
 *
 * Both are rendered from the importer's own column list, so they cannot
 * describe a file the importer would not accept. Nothing is stored on disk —
 * a checked-in copy is a copy that goes stale the first time a column moves.
 */
final class ImportFiles
{
    /**
     * The header row, plus one filled-in row showing the shape of a value.
     * The example row is deleted by the person filling it in, and is worth
     * more than an empty file: most formatting mistakes are answered by
     * having one correct line to copy.
     */
    public function template(Importer $importer): StreamedResponse
    {
        $columns = $importer->columns();

        return $this->stream(
            "{$importer->key()}-import-template.csv",
            function () use ($columns): void {
                $handle = fopen('php://output', 'w');

                if ($handle === false) {
                    return;
                }

                // Excel opens a UTF-8 CSV as the local codepage unless the
                // file leads with a byte order mark, which mangles any name
                // with an accent in it.
                fwrite($handle, "\xEF\xBB\xBF");

                fputcsv($handle, array_map(
                    fn (ImportColumn $column): string => $column->name,
                    $columns,
                ));

                fputcsv($handle, array_map(
                    fn (ImportColumn $column): string => $column->example ?? '',
                    $columns,
                ));

                fclose($handle);
            },
        );
    }

    /**
     * The column reference, as a CSV rather than a document: it is read
     * beside the template, in the same spreadsheet, and a person filling in
     * a file should not have to leave it to find out what a column wants.
     */
    public function reference(Importer $importer): StreamedResponse
    {
        return $this->stream(
            "{$importer->key()}-import-reference.csv",
            function () use ($importer): void {
                $handle = fopen('php://output', 'w');

                if ($handle === false) {
                    return;
                }

                fwrite($handle, "\xEF\xBB\xBF");

                foreach ($this->referenceRows($importer) as $row) {
                    fputcsv($handle, $row);
                }

                fclose($handle);
            },
        );
    }

    /**
     * The reference as rows, shared by the download and the artisan command
     * that writes the set out to disk.
     *
     * @return array<int, array<int, string>>
     */
    public function referenceRows(Importer $importer): array
    {
        $rows = [
            [mb_strtoupper($importer->label()).' IMPORT'],
            [$importer->description()],
            [],
            ['Template file', "{$importer->key()}-import-template.csv"],
            ['Rows are matched on', $importer->matchedOn()],
            ['Permission needed', $importer->permission()->label()],
        ];

        if ($importer->dependsOn() !== []) {
            $rows[] = ['Import these first', implode(', ', $importer->dependsOn())];
        }

        if ($importer->notes() !== []) {
            $rows[] = [];
            $rows[] = ['BEFORE YOU START'];

            foreach ($importer->notes() as $note) {
                $rows[] = ['', $note];
            }
        }

        $rows[] = [];
        $rows[] = ['COLUMNS'];
        $rows[] = ['Column', 'Required', 'Accepts', 'Example', 'What it is'];

        foreach ($importer->columns() as $column) {
            $rows[] = [
                $column->name,
                $column->required ? 'Yes' : 'No',
                $column->accepted(),
                $column->example ?? '',
                $column->description,
            ];
        }

        $rows[] = [];
        $rows[] = ['HOW THE FILE IS READ'];

        foreach ($this->conventions() as $convention) {
            $rows[] = ['', $convention];
        }

        return $rows;
    }

    /**
     * The rules that hold for every import, stated once on every reference
     * so nobody has to have read a different one first.
     *
     * @return array<int, string>
     */
    public function conventions(): array
    {
        return [
            'The first line must be the header row. Column order does not matter, and capitals, spaces, hyphens and underscores in a header are all treated the same.',
            'Columns the import does not recognise are ignored, so you can keep your own working columns in the file.',
            'A column left out of the file entirely is left alone on records that already exist, rather than being emptied.',
            'Dates may be written 2026-01-31, 31/01/2026 or 31 Jan 2026.',
            'Yes/no columns accept yes, no, true, false, 1 and 0.',
            'Where a cell holds several values, separate them with a semicolon — not a comma, which is the column separator.',
            'Blank lines are skipped. A file may hold at most '.number_format(CsvReader::MAX_ROWS).' rows.',
            'Check the file before importing it: the check writes nothing and reports exactly what a real import would do.',
        ];
    }

    private function stream(string $filename, callable $writer): StreamedResponse
    {
        return response()->streamDownload($writer, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
