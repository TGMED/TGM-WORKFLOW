<?php

namespace App\Enums;

/**
 * Where in a request's run a decision was taken. Leave collects a relief
 * officer's sign-off before it reaches anyone with approval rights, and a day
 * out of the office takes an administrator's final say after its line; every
 * other decision is an approval proper.
 */
enum ApprovalStage: string
{
    case Relief = 'relief';

    case Approval = 'approval';

    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Relief => 'Relief officer',
            self::Approval => 'Approver',
            self::Admin => 'Administrator',
        };
    }

    /**
     * Whether decisions at this stage count towards `approvals_required`.
     */
    public function countsTowardsApproval(): bool
    {
        return $this !== self::Relief;
    }
}
