<?php

namespace App\Enums;

/**
 * What an import does with a row whose record already exists.
 *
 * Chosen per run rather than fixed per import: the same staff file is a
 * first load one week and a correction the next, and the difference between
 * those two is the operator's intent, not the file's shape.
 */
enum ImportDuplicates: string
{
    /** Write the row over the record that is already there. */
    case Update = 'update';

    /** Leave the record alone and count the row as skipped. */
    case Skip = 'skip';

    /** Treat the row as a mistake and reject it, naming what it collided with. */
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Update => 'Update the existing record',
            self::Skip => 'Leave the existing record alone',
            self::Reject => 'Reject the row',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Update => 'The file wins. Use this to correct records in bulk.',
            self::Skip => 'The database wins. Use this to add only what is missing.',
            self::Reject => 'Nothing is written and the row is listed as an error. Use this when the file is meant to be all new.',
        };
    }

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case): array => [
            'value' => $case->value,
            'label' => $case->label(),
            'description' => $case->description(),
        ], self::cases());
    }
}
