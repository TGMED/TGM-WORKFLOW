<?php

namespace App\Support;

use App\Models\User;

/**
 * The release notes. What they say lives in config/whats_new.php; what this
 * settles is which release is current and whether a given person still has
 * it to read.
 */
class WhatsNew
{
    /**
     * Every release, newest first.
     *
     * @return list<array{version: string, date: string, features: list<array{title: string, description: string}>, fixes: list<array{title: string, description: string}>}>
     */
    public static function releases(): array
    {
        return array_map(fn (array $release): array => [
            'version' => (string) $release['version'],
            'date' => (string) $release['date'],
            'features' => array_values($release['features'] ?? []),
            'fixes' => array_values($release['fixes'] ?? []),
        ], array_values((array) config('whats_new.releases', [])));
    }

    public static function version(): string
    {
        return (string) config('whats_new.releases.0.version');
    }

    /**
     * The latest release's notes to show this person in the popup, or null
     * when they have read it already. Only the newest release is offered:
     * the older ones are on the page, not repeated at somebody who has
     * already been shown them.
     *
     * @return array<string, mixed>|null
     */
    public static function forUser(?User $user): ?array
    {
        if ($user === null || $user->whats_new_seen === self::version()) {
            return null;
        }

        $latest = self::releases()[0] ?? null;

        if ($latest === null) {
            return null;
        }

        return [
            'title' => (string) config('whats_new.title'),
            'lede' => (string) config('whats_new.lede'),
            ...$latest,
        ];
    }

    public static function markSeen(User $user): void
    {
        $user->forceFill(['whats_new_seen' => self::version()])->save();
    }
}
