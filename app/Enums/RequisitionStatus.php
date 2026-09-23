<?php

namespace App\Enums;

/**
 * Where a requisition has got to. Finance approves it, pays it, and it is
 * closed by the requester retiring it: accounting for what was spent.
 */
enum RequisitionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Declined = 'declined';
    case Paid = 'paid';
    case Retired = 'retired';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting on finance',
            self::Approved => 'Approved',
            self::Declined => 'Declined',
            self::Paid => 'Paid',
            self::Retired => 'Retired',
            self::Withdrawn => 'Withdrawn',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'brass',
            self::Approved => 'beacon',
            self::Declined => 'alert',
            self::Paid => 'signal',
            self::Retired, self::Withdrawn => 'neutral',
        };
    }

    /**
     * Whether money has been granted against it, and so it has to be
     * accounted for.
     */
    public function canBeRetired(): bool
    {
        return $this === self::Approved || $this === self::Paid;
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
