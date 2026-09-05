<?php

namespace App\Imports;

/**
 * What a run of an import did, row by row.
 *
 * The same object comes back from a dry run and from a committed one; only
 * the `committed` flag tells them apart, so the page renders one summary
 * either way and nobody has to learn two.
 */
final class ImportResult
{
    public int $created = 0;

    public int $updated = 0;

    public int $skipped = 0;

    /**
     * How many failures are listed in full. A file whose header row is wrong
     * fails every row it has, and five thousand identical messages help
     * nobody and do not belong in a session flash. The count is always the
     * true one; only the list is cut.
     */
    public const LISTED_FAILURES = 100;

    /**
     * Keyed by file row number, so a person can find the line in their
     * spreadsheet rather than count rows themselves.
     *
     * @var array<int, array{row: int, messages: array<int, string>, values: array<string, string>}>
     */
    public array $failures = [];

    private int $failed = 0;

    public function __construct(public readonly bool $committed = false) {}

    public function created(): void
    {
        $this->created++;
    }

    public function updated(): void
    {
        $this->updated++;
    }

    public function skipped(): void
    {
        $this->skipped++;
    }

    /**
     * @param  array<int, string>  $messages
     * @param  array<string, string>  $values
     */
    public function failed(int $row, array $messages, array $values = []): void
    {
        $this->failed++;

        if (count($this->failures) >= self::LISTED_FAILURES) {
            return;
        }

        $this->failures[] = [
            'row' => $row,
            'messages' => array_values($messages),
            // Enough of the row to recognise it, not the whole thing: a
            // failure list is read on screen, and some of these files carry
            // forty columns.
            'values' => array_slice($values, 0, 4),
        ];
    }

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    public function failedCount(): int
    {
        return $this->failed;
    }

    public function touched(): int
    {
        return $this->created + $this->updated;
    }

    public function total(): int
    {
        return $this->created + $this->updated + $this->skipped + $this->failed;
    }

    /**
     * One line saying what happened, for the toast.
     */
    public function summary(): string
    {
        $parts = [];

        if ($this->created > 0) {
            $parts[] = $this->created.' created';
        }

        if ($this->updated > 0) {
            $parts[] = $this->updated.' updated';
        }

        if ($this->skipped > 0) {
            $parts[] = $this->skipped.' skipped';
        }

        if ($this->hasFailures()) {
            $parts[] = $this->failed.' rejected';
        }

        if ($parts === []) {
            return 'The file held no rows.';
        }

        $verb = $this->committed ? 'Imported' : 'Checked';

        return $verb.' '.$this->total().' rows: '.implode(', ', $parts).'.';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'committed' => $this->committed,
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
            'listed_failures' => count($this->failures),
            'total' => $this->total(),
            'failures' => $this->failures,
            'summary' => $this->summary(),
        ];
    }
}
