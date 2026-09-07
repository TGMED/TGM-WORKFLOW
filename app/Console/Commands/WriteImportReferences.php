<?php

namespace App\Console\Commands;

use App\Imports\Contracts\Importer;
use App\Imports\ImportFiles;
use App\Imports\ImportRegistry;
use Illuminate\Console\Command;

/**
 * Writes the template and reference file for every import out to disk.
 *
 * The pair are served from the import pages already, rendered from the
 * importers themselves. This is for the times somebody wants the whole set
 * as files: a handover pack for a company being onboarded, or an attachment
 * to a support ticket, without asking them to click eleven download links.
 */
class WriteImportReferences extends Command
{
    protected $signature = 'import:reference
                            {import? : One import by key, rather than all of them}
                            {--path= : Where to write them, defaulting to storage/app/imports}';

    protected $description = 'Write the CSV template and column reference for each import to disk';

    public function handle(ImportRegistry $registry, ImportFiles $files): int
    {
        $key = $this->argument('import');

        if ($key !== null && $registry->find((string) $key) === null) {
            $this->error("There is no import called {$key}. Try one of: ".implode(', ', $registry->keys()).'.');

            return self::FAILURE;
        }

        $importers = $key === null
            ? $registry->all()
            : [$registry->find((string) $key)];

        $path = rtrim((string) ($this->option('path') ?? storage_path('app/imports')), '/');

        if (! is_dir($path) && ! mkdir($path, 0755, true) && ! is_dir($path)) {
            $this->error("Could not create {$path}.");

            return self::FAILURE;
        }

        foreach (array_filter($importers) as $importer) {
            $this->write($path, $importer, $files);
        }

        $this->newLine();
        $this->info('Written to '.$path.'.');

        return self::SUCCESS;
    }

    private function write(string $path, Importer $importer, ImportFiles $files): void
    {
        $template = "{$path}/{$importer->key()}-import-template.csv";
        $reference = "{$path}/{$importer->key()}-import-reference.csv";

        // The download streams straight to the browser, so it is replayed
        // into a buffer here rather than the writing being duplicated.
        file_put_contents($template, $this->capture(fn () => $files->template($importer)->sendContent()));

        $handle = fopen($reference, 'w');

        if ($handle === false) {
            $this->error("Could not write {$reference}.");

            return;
        }

        fwrite($handle, "\xEF\xBB\xBF");

        foreach ($files->referenceRows($importer) as $row) {
            fputcsv($handle, $row);
        }

        fclose($handle);

        $this->line("  <fg=green>✓</> {$importer->label()}");
    }

    private function capture(callable $writer): string
    {
        ob_start();
        $writer();

        return (string) ob_get_clean();
    }
}
