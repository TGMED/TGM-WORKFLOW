<?php

namespace App\Enums;

/**
 * Why somebody is working away from their site. Both kinds are work, which is
 * what separates them from leave: the day is not deducted from an allowance
 * and the person is not away, only elsewhere.
 */
enum OutOfOfficeKind: string
{
    case Remote = 'remote';
    case Assignment = 'assignment';

    public function label(): string
    {
        return match ($this) {
            self::Remote => 'Working from home',
            self::Assignment => 'Official assignment',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Remote => 'Working your usual hours somewhere other than the office.',
            self::Assignment => 'Company business that takes you out of the office.',
        };
    }

    /**
     * Whether the day is spent somewhere the company sent them, which is the
     * only kind that needs to say where and how to be reached.
     */
    public function needsDestination(): bool
    {
        return $this === self::Assignment;
    }

    public function tone(): string
    {
        return $this === self::Assignment ? 'beacon' : 'neutral';
    }

    /**
     * @return array<int, array{value: string, label: string, description: string, needs_destination: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $kind): array => [
            'value' => $kind->value,
            'label' => $kind->label(),
            'description' => $kind->description(),
            'needs_destination' => $kind->needsDestination(),
        ], self::cases());
    }
}
