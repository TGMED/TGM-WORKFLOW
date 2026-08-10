<?php

namespace App\Enums;

/**
 * Shared lifecycle for anything that goes through approval.
 */
enum RequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Sent back by a relief officer for the requester to redo. */
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
            self::Returned => 'Returned',
        };
    }

    /**
     * Matches the tones understood by the StatusPill component.
     */
    public function tone(): string
    {
        return match ($this) {
            self::Pending => 'brass',
            self::Approved => 'signal',
            self::Rejected => 'alert',
            self::Cancelled => 'neutral',
            self::Returned => 'brass',
        };
    }

    public function isOpen(): bool
    {
        return $this === self::Pending;
    }
}
