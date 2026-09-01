<?php

namespace App\Enums;

enum AttendanceStatus: string
{
    case OnTime = 'on_time';
    case Grace = 'grace';
    case Late = 'late';

    public function label(): string
    {
        return match ($this) {
            self::OnTime => 'On time',
            self::Grace => 'Within grace',
            self::Late => 'Late',
        };
    }

    /**
     * Palette tone for this status: green on time, amber inside the grace
     * window, red once the grace window has closed.
     */
    public function tone(): string
    {
        return match ($this) {
            self::OnTime => 'signal',
            self::Grace => 'brass',
            self::Late => 'alert',
        };
    }

    /**
     * Arriving inside the grace window is deliberately not late, so nothing
     * that counts lateness should count a grace day.
     */
    public function isLate(): bool
    {
        return $this === self::Late;
    }
}
