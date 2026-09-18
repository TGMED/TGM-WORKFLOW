<?php

namespace App\Enums;

/**
 * Where a recommendation to terminate has got to.
 *
 * Deliberately not the ordinary request statuses: this does not travel an
 * approval chain and is not granted by collecting signatures. One person puts
 * it to the people team, and the people team answers it.
 */
enum RecommendationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Declined = 'declined';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'With HR',
            self::Accepted => 'Accepted',
            self::Declined => 'Declined',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'brass',
            self::Accepted => 'alert',
            self::Declined => 'neutral',
            self::Withdrawn => 'neutral',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $case): array => ['value' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}
