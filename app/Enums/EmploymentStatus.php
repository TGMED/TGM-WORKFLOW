<?php

namespace App\Enums;

/**
 * Where somebody stands against their probation. Leaving the company is not
 * a case here: `users.is_active` already carries that, and a second place to
 * read it from would only be a second place to get it wrong.
 */
enum EmploymentStatus: string
{
    case Probation = 'probation';
    case Confirmed = 'confirmed';

    public function label(): string
    {
        return match ($this) {
            self::Probation => 'On probation',
            self::Confirmed => 'Confirmed',
        };
    }

    /**
     * Matches the tones understood by the StatusPill component.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Probation => 'brass',
            self::Confirmed => 'signal',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status): array => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
