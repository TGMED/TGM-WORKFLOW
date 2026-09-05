import type { Permission } from './auth';

/** Mirrors App\Enums\ImportDuplicates. */
export type ImportDuplicates = 'update' | 'skip' | 'reject';

export type ImportColumn = {
    name: string;
    description: string;
    example: string | null;
    required: boolean;
    /** The closed set of values, or the shape one has to take. */
    accepted: string;
};

/** One importable part of the system, as the index lists it. */
export type ImportSummary = {
    key: string;
    label: string;
    description: string;
    matched_on: string;
    /** Keys of the imports that should be run before this one. */
    depends_on: string[];
    permission: Permission;
    permission_label: string;
    column_count: number;
    required_columns: string[];
};

export type ImportDetail = ImportSummary & {
    notes: string[];
    columns: ImportColumn[];
};

export type ImportFailure = {
    /** The line in the file, counting the header as 1. */
    row: number;
    messages: string[];
    values: Record<string, string>;
};

export type ImportResult = {
    /** False when the file was only checked, and nothing was written. */
    committed: boolean;
    created: number;
    updated: number;
    skipped: number;
    failed: number;
    /** How many of the failures below are listed; the rest are counted only. */
    listed_failures: number;
    total: number;
    failures: ImportFailure[];
    summary: string;
};

export type DuplicateOption = {
    value: ImportDuplicates;
    label: string;
    description: string;
};
