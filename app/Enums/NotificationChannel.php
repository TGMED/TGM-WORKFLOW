<?php

namespace App\Enums;

/**
 * The ways a notification can reach someone, both of which they control per
 * topic. In-app toasts are not listed: they are part of the page, not
 * something sent.
 */
enum NotificationChannel: string
{
    case Email = 'email';

    case Push = 'push';

    public function label(): string
    {
        return match ($this) {
            self::Email => 'Email',
            self::Push => 'Push',
        };
    }
}
