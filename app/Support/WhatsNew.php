<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

/**
 * The release notes. What they say lives in config/whats_new.php; what this
 * settles is which release is current, which of its notes are meant for a
 * given person, and whether they still have it to read.
 */
class WhatsNew
{
    /**
     * Every release, newest first, carrying only the notes meant for this
     * person. A release left with nothing for them is dropped altogether.
     *
     * @return list<array{version: string, date: string, features: list<array{title: string, description: string}>, fixes: list<array{title: string, description: string}>}>
     */
    public static function releases(User $user): array
    {
        $releases = array_map(fn (array $release): array => [
            'version' => (string) $release['version'],
            'date' => (string) $release['date'],
            'features' => self::notesFor($user, $release['features'] ?? []),
            'fixes' => self::notesFor($user, $release['fixes'] ?? []),
        ], array_values((array) config('whats_new.releases', [])));

        return array_values(array_filter(
            $releases,
            fn (array $release): bool => $release['features'] !== [] || $release['fixes'] !== [],
        ));
    }

    public static function version(): string
    {
        return (string) config('whats_new.releases.0.version');
    }

    /**
     * The latest release's notes to show this person in the popup, or null
     * when they have read it already or none of it concerns them. Only the
     * newest release is offered: the older ones are on the page, not
     * repeated at somebody who has already been shown them.
     *
     * @return array<string, mixed>|null
     */
    public static function forUser(?User $user): ?array
    {
        if ($user === null || $user->whats_new_seen === self::version()) {
            return null;
        }

        $latest = self::releases($user)[0] ?? null;

        if ($latest === null || $latest['version'] !== self::version()) {
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

    /**
     * Whether a note's audience takes in this person. No audience means
     * everybody; otherwise matching any one entry is enough.
     *
     * @param  list<string>  $audience
     */
    public static function reaches(User $user, array $audience): bool
    {
        if ($audience === []) {
            return true;
        }

        foreach ($audience as $entry) {
            $matches = match ($entry) {
                'staff' => $user->clocksIn(),
                'approvers' => $user->canApprove(),
                'heads' => $user->headsADepartment(),
                'admins' => $user->isSuperAdmin(),
                // Anything else names a permission, and an unknown one throws
                // rather than quietly hiding the note from everybody.
                default => $user->hasPermission(Permission::from($entry)),
            };

            if ($matches) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $notes
     * @return list<array{title: string, description: string}>
     */
    private static function notesFor(User $user, array $notes): array
    {
        $kept = [];

        foreach ($notes as $note) {
            // A mistyped entry throws, as an unknown permission does, rather
            // than quietly hiding the note from everybody.
            $audience = array_map(
                fn (mixed $entry): string => is_string($entry)
                    ? $entry
                    : throw new \InvalidArgumentException('A note audience lists names, not '.get_debug_type($entry).'.'),
                array_values((array) ($note['audience'] ?? [])),
            );

            if (self::reaches($user, $audience)) {
                $kept[] = [
                    'title' => (string) $note['title'],
                    'description' => (string) $note['description'],
                ];
            }
        }

        return $kept;
    }
}
