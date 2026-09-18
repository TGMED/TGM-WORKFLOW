<?php

namespace App\Console\Commands;

use App\Services\ApprovalEscalation;
use Illuminate\Console\Command;

/**
 * Chases requests that have sat undecided past their module's stretch. Runs
 * hourly; finds nothing at all on a company that keeps up with its approvals.
 */
class EscalateStaleApprovals extends Command
{
    protected $signature = 'approvals:escalate';

    protected $description = 'Tell the people team about requests nobody has decided';

    public function handle(ApprovalEscalation $escalation): int
    {
        $escalated = $escalation->run();

        $this->info($escalated === 0
            ? 'Nothing has been waiting long enough to chase.'
            : "Chased {$escalated} request(s).");

        return self::SUCCESS;
    }
}
