<?php

namespace App\Enums;

/**
 * How the handbook is filed. Fixed in code rather than editable: each one is
 * a heading staff are expected to recognise, and a company that invents its
 * own ends up with three names for the same thing.
 */
enum PolicyCategory: string
{
    case Handbook = 'handbook';
    case Conduct = 'conduct';
    case Leave = 'leave';
    case Safety = 'safety';
    case Technology = 'technology';
    case Finance = 'finance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Handbook => 'Employee handbook',
            self::Conduct => 'Conduct and discipline',
            self::Leave => 'Leave and time off',
            self::Safety => 'Health and safety',
            self::Technology => 'Technology and data',
            self::Finance => 'Pay and expenses',
            self::Other => 'Other',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Handbook => 'The document everything else hangs off.',
            self::Conduct => 'What is expected, and what follows when it is not met.',
            self::Leave => 'Holiday, sickness and the rest of the time-off rules.',
            self::Safety => 'Working safely, and what to do when something goes wrong.',
            self::Technology => 'Equipment, systems and the handling of data.',
            self::Finance => 'Pay, expenses and anything else with money in it.',
            self::Other => 'Anything the headings above do not cover.',
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
