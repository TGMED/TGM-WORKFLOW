<?php

namespace App\Enums;

/**
 * Who may read a performance review besides HR.
 */
enum ReviewVisibility: string
{
    /** The subject reads it, without being told who wrote it. */
    case Public = 'public';

    /** HR alone. The subject is not told it exists. */
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Public => 'Shared with them',
            self::Private => 'HR only',
        };
    }

    /**
     * Matches the tones understood by the StatusPill component.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Public => 'signal',
            self::Private => 'brass',
        };
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
