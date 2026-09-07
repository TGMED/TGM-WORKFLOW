<?php

namespace App\Imports;

use RuntimeException;

/**
 * A row the importer will not write, and why.
 *
 * Thrown rather than returned so an importer can give up at the point it
 * finds the problem — halfway through resolving a foreign key, say — without
 * threading a null back out through everything it has already done.
 */
final class RowRejected extends RuntimeException
{
    /**
     * @param  array<int, string>  $messages
     */
    private function __construct(public readonly array $messages)
    {
        parent::__construct($messages[0] ?? 'The row was rejected.');
    }

    public static function because(string ...$messages): self
    {
        return new self(array_values($messages));
    }

    /**
     * @param  array<int, string>  $messages
     */
    public static function withAll(array $messages): self
    {
        return new self(array_values($messages));
    }
}
