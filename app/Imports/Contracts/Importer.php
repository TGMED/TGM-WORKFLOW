<?php

namespace App\Imports\Contracts;

use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;

/**
 * One importable part of the system.
 *
 * An importer owns three things and nothing else: what its file looks like,
 * what a row means, and what to do with it. Reading the file, running the
 * transaction, counting the outcome and rendering the template all belong to
 * the machinery around it, so adding a new import is a matter of describing
 * columns and writing one row.
 */
interface Importer
{
    /**
     * The URL segment and the template's filename. Kebab-case.
     */
    public function key(): string;

    /**
     * What the import is called on the page.
     */
    public function label(): string;

    /**
     * One sentence on what a file of these does.
     */
    public function description(): string;

    /**
     * The permission somebody needs to run it. Deliberately the same
     * permission that guards editing these records one at a time: a bulk
     * upload must never be a way round a page somebody cannot reach.
     */
    public function permission(): Permission;

    /**
     * Which imports should be run before this one, by key. Staff cannot be
     * placed at a site that is not on file yet, and the page says so rather
     * than letting somebody find out row by row.
     *
     * @return array<int, string>
     */
    public function dependsOn(): array;

    /**
     * The file's columns, in the order the template writes them.
     *
     * @return array<int, ImportColumn>
     */
    public function columns(): array;

    /**
     * How a row is matched to a record that already exists, said in words.
     * Shown on the page, because "what counts as the same row" is the one
     * thing an operator has to understand before uploading anything.
     */
    public function matchedOn(): string;

    /**
     * Anything worth knowing before filling the file in: a rule the columns
     * cannot express, a default that will be applied, a gotcha.
     *
     * @return array<int, string>
     */
    public function notes(): array;

    /**
     * Apply one row, recording what happened against the result.
     *
     * Called inside a transaction that is rolled back on a dry run, so an
     * importer writes exactly the same way either way and has no dry-run
     * branch of its own to get wrong.
     */
    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void;
}
