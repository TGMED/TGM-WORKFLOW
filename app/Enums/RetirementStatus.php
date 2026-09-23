<?php

namespace App\Enums;

enum RetirementStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Queried = 'queried';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'With finance',
            self::Accepted => 'Accepted',
            self::Queried => 'Sent back',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'brass',
            self::Accepted => 'signal',
            self::Queried => 'alert',
        };
    }
}
