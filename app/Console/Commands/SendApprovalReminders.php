<?php

namespace App\Console\Commands;

use App\Services\ApprovalReminders;
use Illuminate\Console\Command;

/**
 * Each working morning, reminds approvers of what is waiting on them and
 * gives super admins the list of everything still pending.
 */
class SendApprovalReminders extends Command
{
    protected $signature = 'approvals:remind';

    protected $description = 'Email approvers and super admins about requests still waiting for a decision';

    public function handle(ApprovalReminders $reminders): int
    {
        if (! config('hr.approval_reminders.enabled')) {
            $this->info('Approval reminders are switched off.');

            return self::SUCCESS;
        }

        $sent = $reminders->run();

        $this->info($sent['requests'] === 0
            ? 'Nothing is waiting long enough to remind anyone about.'
            : "{$sent['requests']} pending request(s): reminded {$sent['approvers']} approver(s) and {$sent['admins']} super admin(s).");

        return self::SUCCESS;
    }
}
