<?php

namespace App\Enums;

/**
 * A run is a draft until somebody signs it off. Only a finalised run is
 * visible to staff, and only a draft can be rebuilt or thrown away.
 */
enum PayrollRunStatus: string
{
    case Draft = 'draft';
    case Finalised = 'finalised';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Finalised => 'Finalised',
        };
    }

    /**
     * Matches the tones understood by the StatusPill component.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Draft => 'brass',
            self::Finalised => 'signal',
        };
    }

    public function isDraft(): bool
    {
        return $this === self::Draft;
    }
}
