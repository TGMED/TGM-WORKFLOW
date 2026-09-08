<?php

namespace App\Enums;

/**
 * Why somebody stopped working here. Recorded against the exit so the
 * headcount that walked out of the door can be accounted for later.
 */
enum ExitReason: string
{
    case Resignation = 'resignation';
    case EndOfContract = 'end_of_contract';
    case Redundancy = 'redundancy';
    case Dismissal = 'dismissal';
    case Retirement = 'retirement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Resignation => 'Resigned',
            self::EndOfContract => 'Contract ended',
            self::Redundancy => 'Made redundant',
            self::Dismissal => 'Dismissed',
            self::Retirement => 'Retired',
            self::Other => 'Other',
        };
    }

    /**
     * Whether the parting was the company's decision. Kept apart from the
     * label because turnover reporting reads very differently depending on
     * who did the leaving.
     */
    public function wasCompanyDecision(): bool
    {
        return match ($this) {
            self::Redundancy, self::Dismissal, self::EndOfContract => true,
            default => false,
        };
    }

    /**
     * Matches the tones understood by the StatusPill component.
     */
    public function tone(): string
    {
        return $this === self::Dismissal ? 'alert' : 'neutral';
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $reason): array => ['value' => $reason->value, 'label' => $reason->label()],
            self::cases(),
        );
    }
}
