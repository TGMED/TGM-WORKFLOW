<?php

namespace App\Enums;

/**
 * How seriously the policy treats an offence. It decides nothing on its own:
 * what follows a given offence is the ladder written against it. This is for
 * reading a register at a glance and for sorting it.
 */
enum OffenceSeverity: string
{
    case Minor = 'minor';
    case Serious = 'serious';
    case Gross = 'gross';

    public function label(): string
    {
        return match ($this) {
            self::Minor => 'Minor',
            self::Serious => 'Serious',
            self::Gross => 'Gross misconduct',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Minor => 'Put right with a word, unless it keeps happening.',
            self::Serious => 'Formal from the first occurrence.',
            self::Gross => 'May end the employment at the first occurrence.',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Minor => 'neutral',
            self::Serious => 'brass',
            self::Gross => 'alert',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Minor => 1,
            self::Serious => 2,
            self::Gross => 3,
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
