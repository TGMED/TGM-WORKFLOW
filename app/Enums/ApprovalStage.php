<?php

namespace App\Enums;

/**
 * Where in a request's run a decision was taken. Leave collects a relief
 * officer's sign-off before it reaches anyone with approval rights; every
 * other decision is an approval proper.
 */
enum ApprovalStage: string
{
    case Relief = 'relief';

    case Approval = 'approval';

    public function label(): string
    {
        return match ($this) {
            self::Relief => 'Relief officer',
            self::Approval => 'Approver',
        };
    }

    /**
     * Whether decisions at this stage count towards `approvals_required`.
     */
    public function countsTowardsApproval(): bool
    {
        return $this === self::Approval;
    }
}
