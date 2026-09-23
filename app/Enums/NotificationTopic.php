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

    /** Where you sit in the company changed: a department, or the running of one. */
    case Department = 'department';

    /** A request somebody raised has been waiting too long to be decided. */
    case ApprovalOverdue = 'approval_overdue';

    /** Somebody has been recommended for termination, or that case was answered. */
    case TerminationRecommended = 'termination_recommended';

    /** A query, warning or confirmation was issued, or a query answered. */
    case StaffAction = 'staff_action';

    /** A requisition or its retirement was raised, decided or paid. */
    case Requisition = 'requisition';

    public function label(): string
    {
        return match ($this) {
            self::ApprovalRequested => 'Approvals waiting on me',
            self::RequestRaised => 'Requests as they are raised',
            self::RequestDecided => 'Decisions on my requests',
            self::Announcement => 'Company announcements',
            self::Birthday => 'Birthday greetings',
            self::Anniversary => 'Work anniversaries',
            self::Department => 'Department changes',
            self::ApprovalOverdue => 'Requests left waiting',
            self::TerminationRecommended => 'Terminations recommended',
            self::StaffAction => 'Queries, warnings and confirmations',
            self::Requisition => 'Requisitions',
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
            self::Department => 'You were moved into a department, or named to run one.',
            self::ApprovalOverdue => 'A request has sat undecided long enough that somebody should chase it.',
            self::TerminationRecommended => 'A case has been put to HR that somebody be let go, or one you raised has been answered.',
            self::StaffAction => 'You, or somebody in your department, has been queried, warned or confirmed, or a query has been answered.',
            self::Requisition => 'A requisition you raised was approved, declined or paid, or one is waiting on finance.',
        };
    }

    /**
     * Topics nobody should be able to switch off entirely. Approvals hold up
     * somebody else's time off, so they are not optional, and neither is a
     * formal letter about somebody's standing.
     */
    public function isRequired(): bool
    {
        // A query or warning is a formal letter: it has to reach the person
        // it is about, and the people accountable for them, whatever their
        // settings say.
        return $this === self::ApprovalRequested || $this === self::StaffAction;
    }
}
