<?php

namespace App\Enums;

/**
 * The letters HR sends somebody about their standing.
 */
enum StaffActionKind: string
{
    case Query = 'query';
    case Warning = 'warning';
    case Confirmation = 'confirmation';

    public function label(): string
    {
        return match ($this) {
            self::Query => 'Query',
            self::Warning => 'Warning',
            self::Confirmation => 'Confirmation',
        };
    }

    /**
     * Matches the tones understood by the StatusPill component.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Query => 'brass',
            self::Warning => 'alert',
            self::Confirmation => 'signal',
        };
    }

    /**
     * A query asks the person to answer it. The others are read and
     * acknowledged.
     */
    public function expectsResponse(): bool
    {
        return $this === self::Query;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
