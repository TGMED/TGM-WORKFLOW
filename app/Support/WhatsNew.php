<?php

namespace App\Support;

use App\Models\User;

/**
 * The release notes popup. What it says lives in config/whats_new.php; what
 * this settles is whether a given person still has it to read.
 */
class WhatsNew
{
    public static function version(): string
    {
        return (string) config('whats_new.version');
    }

    /**
     * The notes to show this person, or null when they have read this release
     * already. Somebody who has never seen the popup is shown it once, which
     * is the same thing that happens on every later release.
     *
     * @return array<string, mixed>|null
     */
    public static function forUser(?User $user): ?array
    {
        if ($user === null || $user->whats_new_seen === self::version()) {
            return null;
        }

        return [
            'version' => self::version(),
            'title' => (string) config('whats_new.title'),
            'lede' => (string) config('whats_new.lede'),
            'features' => (array) config('whats_new.features', []),
        ];
    }
}
