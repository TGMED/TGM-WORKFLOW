<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ImportDuplicates;
use App\Http\Controllers\Controller;
use App\Http\Requests\RunImportRequest;
use App\Imports\Contracts\Importer;
use App\Imports\ImportColumn;
use App\Imports\ImportFiles;
use App\Imports\ImportRegistry;
use App\Imports\ImportRunner;
use App\Imports\RowRejected;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bringing records in from a spreadsheet.
 *
 * Everything on the page comes off the importers themselves — the list, the
 * columns, the template, the reference. Adding an import is a matter of
 * writing the class and naming it in the registry; nothing here has to be
 * touched to make it appear.
 */
class ImportController extends Controller
{
    public function __construct(
        private readonly ImportRegistry $registry,
        private readonly ImportFiles $files,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('admin/Imports', [
            'imports' => array_map(
                fn (Importer $importer): array => $this->summary($importer),
                $user === null ? [] : $this->registry->availableTo($user),
            ),
            'conventions' => $this->files->conventions(),
        ]);
    }

    public function show(Request $request, Importer $importer): Response
    {
        return Inertia::render('admin/Import', [
            // The outcome of the run that redirected back here. Page-local
            // rather than a shared flash key: no other page has any use for
            // it, and a result carries a row-by-row failure list.
            'result' => fn () => $request->session()->get('import_result'),
            // Named `sheet` rather than `import`: `import` is a reserved word
            // in a Vue template expression and cannot be read there.
            'sheet' => [
                ...$this->summary($importer),
                'notes' => $importer->notes(),
                'columns' => array_map(
                    fn (ImportColumn $column): array => $column->toArray(),
                    $importer->columns(),
                ),
            ],
            'conventions' => $this->files->conventions(),
            'duplicate_options' => ImportDuplicates::options(),
        ]);
    }

    /**
     * The file somebody fills in: the header row, and one example row
     * showing the shape of a value.
     */
    public function template(Importer $importer): StreamedResponse
    {
        return $this->files->template($importer);
    }

    /**
     * The column reference that goes with it, as a CSV so it opens beside
     * the template in the same spreadsheet.
     */
    public function reference(Importer $importer): StreamedResponse
    {
        return $this->files->reference($importer);
    }

    /**
     * Check a file, or import it. The same code path runs either way; a check
     * is rolled back at the end, so what it reports is what committing would
     * actually do.
     */
    public function store(RunImportRequest $request, Importer $importer, ImportRunner $runner): RedirectResponse
    {
        try {
            $result = $runner->run(
                $importer,
                $request->csv(),
                $request->duplicates(),
                $request->commits(),
            );
        } catch (RowRejected $e) {
            // Thrown before any row was read: the wrong file, or the right
            // one saved as something else.
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()
            ->with('import_result', $result->toArray())
            ->with('toast', [
                'type' => $result->hasFailures() ? 'error' : 'success',
                'message' => $result->summary(),
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Importer $importer): array
    {
        return [
            'key' => $importer->key(),
            'label' => $importer->label(),
            'description' => $importer->description(),
            'matched_on' => $importer->matchedOn(),
            'depends_on' => $importer->dependsOn(),
            'permission' => $importer->permission()->value,
            'permission_label' => $importer->permission()->label(),
            'column_count' => count($importer->columns()),
            'required_columns' => array_values(array_map(
                fn (ImportColumn $column): string => $column->name,
                array_filter(
                    $importer->columns(),
                    fn (ImportColumn $column): bool => $column->required,
                ),
            )),
        ];
    }
}
