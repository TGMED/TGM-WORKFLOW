<?php

namespace App\Enums;

/**
 * Where an asset stands. "Assigned" is set by handing it to somebody, never
 * picked, so the list and the holder cannot disagree.
 */
enum AssetStatus: string
{
    case Available = 'available';
    case Assigned = 'assigned';
    case InRepair = 'in_repair';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Available',
            self::Assigned => 'Assigned',
            self::InRepair => 'In repair',
            self::Retired => 'Retired',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Available => 'signal',
            self::Assigned => 'beacon',
            self::InRepair => 'brass',
            self::Retired => 'neutral',
        };
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
