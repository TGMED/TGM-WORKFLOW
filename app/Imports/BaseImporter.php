<?php

namespace App\Imports;

use App\Imports\Contracts\Importer;
use Illuminate\Support\Facades\Validator;

/**
 * The parts every importer would otherwise write for itself: validating the
 * values pulled off a row, and giving up on one.
 */
abstract class BaseImporter implements Importer
{
    /**
     * @return array<int, string>
     */
    public function dependsOn(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [];
    }

    /**
     * The same rules the single-record forms use, applied to a row.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @param  array<string, string>  $messages
     * @param  array<string, string>  $attributes
     * @return array<string, mixed>
     */
    protected function validate(array $data, array $rules, array $messages = [], array $attributes = []): array
    {
        $validator = Validator::make($data, $rules, $messages, $attributes);

        if ($validator->fails()) {
            /** @var array<int, string> $errors */
            $errors = $validator->errors()->all();

            throw RowRejected::withAll($errors);
        }

        /** @var array<string, mixed> $validated */
        $validated = $validator->validated();

        return $validated;
    }

    /**
     * Give up on the row, saying what is wrong with it in the operator's
     * terms rather than the database's.
     */
    protected function reject(string ...$messages): never
    {
        throw RowRejected::because(...$messages);
    }

    /**
     * Drops the keys a row said nothing about, so an update writes only what
     * the file actually carried. Without this, a correction file holding two
     * columns would blank out every other field on the record.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function present(array $data): array
    {
        return array_filter($data, fn (mixed $value): bool => $value !== null);
    }
}
