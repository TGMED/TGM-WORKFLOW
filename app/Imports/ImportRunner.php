<?php

namespace App\Imports;

use App\Enums\ImportDuplicates;
use App\Imports\Contracts\Importer;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs an uploaded file through an importer.
 *
 * Two things happen here that importers therefore never have to think about.
 * A dry run writes exactly as a real one does and is rolled back at the end,
 * so what the preview reports is what committing would actually do — right
 * down to uniqueness collisions between two rows of the same file. And each
 * row sits in a savepoint of its own, so a row that fails halfway through
 * leaves nothing behind and the rows after it still import.
 */
final class ImportRunner
{
    public function __construct(private readonly CsvReader $reader) {}

    /**
     * @param  bool  $commit  False to check the file and change nothing.
     */
    public function run(
        Importer $importer,
        UploadedFile $file,
        ImportDuplicates $duplicates,
        bool $commit,
    ): ImportResult {
        $result = new ImportResult(committed: $commit);

        $this->assertHeadersUsable($importer, $file);

        DB::beginTransaction();

        try {
            foreach ($this->reader->rows($file) as $row) {
                $this->runRow($importer, $row, $duplicates, $result);
            }
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        // A dry run is a real run that is thrown away. Nothing about the
        // importer knows which of the two it was in.
        //
        // A committed run keeps the rows that worked and leaves out the ones
        // that did not, rather than refusing the whole file over one bad
        // line: the rows are independent of each other, and the operator has
        // already been shown what would happen.
        if ($commit) {
            DB::commit();
        } else {
            DB::rollBack();
        }

        return $result;
    }

    private function runRow(
        Importer $importer,
        ImportRow $row,
        ImportDuplicates $duplicates,
        ImportResult $result,
    ): void {
        DB::beginTransaction();

        try {
            $importer->import($row, $duplicates, $result);

            DB::commit();
        } catch (RowRejected $e) {
            DB::rollBack();

            $result->failed($row->number, $e->messages, $row->preview());
        } catch (QueryException $e) {
            DB::rollBack();

            // The driver's message names columns and constraints nobody
            // outside this codebase should have to read, so it goes to the
            // log and the operator gets the row number and a plain sentence.
            Log::warning('Import row failed', [
                'importer' => $importer->key(),
                'row' => $row->number,
                'error' => $e->getMessage(),
            ]);

            $result->failed(
                $row->number,
                ['The database refused this row. It usually means a value collides with one already on file.'],
                $row->preview(),
            );
        }
    }

    /**
     * A file whose header row carries none of the expected columns is almost
     * always the wrong file, or the right one saved as something other than
     * CSV. Saying so up front beats a thousand identical row errors.
     */
    private function assertHeadersUsable(Importer $importer, UploadedFile $file): void
    {
        $headers = $this->reader->headers($file);

        if ($headers === []) {
            throw RowRejected::because('That file is empty.');
        }

        $expected = array_map(
            fn (ImportColumn $column): string => CsvReader::normalise($column->name),
            $importer->columns(),
        );

        if (array_intersect($headers, $expected) === []) {
            throw RowRejected::because(
                'None of the columns in that file are ones this import recognises. Download the template and check the header row.',
            );
        }

        // Missing required columns are deliberately not checked here. A file
        // that carries only an email and a department is a correction, not a
        // mistake, and refusing it up front would make bulk corrections
        // impossible. A file that really is missing something says so row by
        // row instead.
    }
}
