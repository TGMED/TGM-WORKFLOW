<?php

namespace App\Enums;

/**
 * The things the app writes to people about. Each one is a switch on the
 * notification settings page, so adding a case here adds a row there.
 */
enum NotificationTopic: string
{
    /** Something is waiting on you to decide. */
    case ApprovalRequested = 'approval_requested';

    /** A request has been raised by you, for you, or is heading your way. */
    case RequestRaised = 'request_raised';

    /** A request you raised, or raised for someone, has been ruled on. */
    case RequestDecided = 'request_decided';

    case Announcement = 'announcement';

    case Birthday = 'birthday';

    /** Our note to you on the anniversary of the day you joined. */
    case Anniversary = 'anniversary';

    public function label(): string
    {
        return match ($this) {
            self::ApprovalRequested => 'Approvals waiting on me',
            self::RequestRaised => 'Requests as they are raised',
            self::RequestDecided => 'Decisions on my requests',
            self::Announcement => 'Company announcements',
            self::Birthday => 'Birthday greetings',
            self::Anniversary => 'Work anniversaries',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ApprovalRequested => 'A colleague has sent you leave or lateness to rule on.',
            self::RequestRaised => 'Your request is in, one was filed for you, or one is on its way to you.',
            self::RequestDecided => 'Your request was approved, declined or sent back.',
            self::Announcement => 'Notices published to the whole company.',
            self::Birthday => 'Our note to you on your birthday.',
            self::Anniversary => 'Our note to you on the anniversary of your first day.',
        };
    }

    /**
     * Topics nobody should be able to switch off entirely. Approvals hold up
     * somebody else's time off, so they are not optional.
     */
    public function isRequired(): bool
    {
        return $this === self::ApprovalRequested;
    }
}
