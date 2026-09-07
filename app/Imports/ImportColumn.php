<?php

namespace App\Imports;

/**
 * One column of an import file: what it is called in the header row, whether
 * a row can be left without it, and what a good value looks like.
 *
 * This is the single source of truth for a column. The template, the
 * reference file and the on-screen documentation are all rendered from it,
 * so the three can never drift from each other or from the importer.
 */
final class ImportColumn
{
    /**
     * @param  string  $name  Header as it appears in the file.
     * @param  string  $description  What the column holds, in a sentence.
     * @param  string|null  $example  A value that would be accepted.
     * @param  bool  $required  Whether a row without it is rejected.
     * @param  string|null  $format  The shape a value has to take, where it is not free text.
     * @param  array<int, string>  $accepts  The closed set of values, where there is one.
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly ?string $example = null,
        public readonly bool $required = false,
        public readonly ?string $format = null,
        public readonly array $accepts = [],
    ) {}

    /**
     * @param  array<int, string>  $accepts
     */
    public static function make(
        string $name,
        string $description,
        ?string $example = null,
        bool $required = false,
        ?string $format = null,
        array $accepts = [],
    ): self {
        return new self($name, $description, $example, $required, $format, $accepts);
    }

    /**
     * A required column, which is the case worth reading at a glance.
     *
     * @param  array<int, string>  $accepts
     */
    public static function required(
        string $name,
        string $description,
        ?string $example = null,
        ?string $format = null,
        array $accepts = [],
    ): self {
        return new self($name, $description, $example, true, $format, $accepts);
    }

    /**
     * A yes/no column. Every importer parses these the same way, so they all
     * describe themselves the same way too.
     */
    public static function boolean(string $name, string $description, bool $default = true): self
    {
        return new self(
            name: $name,
            description: $description.' Left blank it is taken as '.($default ? 'yes' : 'no').'.',
            example: $default ? 'yes' : 'no',
            required: false,
            format: 'yes / no',
            accepts: ['yes', 'no', 'true', 'false', '1', '0', 'y', 'n'],
        );
    }

    /**
     * How the reference file and the page describe what will be accepted.
     */
    public function accepted(): string
    {
        if ($this->accepts !== []) {
            return implode(', ', $this->accepts);
        }

        return $this->format ?? 'Text';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'example' => $this->example,
            'required' => $this->required,
            'accepted' => $this->accepted(),
        ];
    }
}
