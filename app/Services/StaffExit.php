<?php

namespace App\Services;

use App\Enums\ExitReason;
use App\Enums\RequestStatus;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Walking a member of staff out of the door.
 *
 * Deactivating an account only stops somebody signing in. An exit is the rest
 * of it: the record of why they went, the requests still in flight that
 * nobody can decide any more, the devices that would otherwise keep buzzing,
 * and the cover they promised colleagues who are still counting on it.
 *
 * Nothing is deleted. Their attendance, their leave history and the audit
 * trail all stay exactly where they are; they simply stop counting towards
 * company metrics, which \App\Models\Concerns\BelongsToStaff sees to.
 */
class StaffExit
{
    /**
     * Record an exit and clear up after it.
     *
     * @throws Throwable
     */
    public function record(
        User $staff,
        ExitReason $reason,
        Carbon $lastDay,
        ?string $note = null,
    ): StaffExitOutcome {
        return DB::transaction(function () use ($staff, $reason, $lastDay, $note): StaffExitOutcome {
            $staff->forceFill([
                'is_active' => false,
                'deactivated_at' => Carbon::now(),
                'exit_reason' => $reason,
                'exit_date' => $lastDay,
                'exit_note' => $note,
            ])->save();

            $cancelled = $this->cancelOpenRequests($staff);
            $orphaned = $this->coverLeftBehind($staff, $lastDay);

            // Their phone should stop buzzing about a job they no longer
            // hold. The rows go rather than being marked dead: a token is
            // worthless the moment it should not be written to.
            $staff->pushTokens()->delete();

            // Signing in is already barred, but a browser they left open is
            // still holding a live session until this clears it.
            DB::table('sessions')->where('user_id', $staff->id)->delete();

            return new StaffExitOutcome($cancelled, $orphaned);
        });
    }

    /**
     * Undo an exit, for the reinstatement or the mistake.
     */
    public function reinstate(User $staff): void
    {
        $staff->forceFill([
            'is_active' => true,
            'deactivated_at' => null,
            'exit_reason' => null,
            'exit_date' => null,
            'exit_note' => null,
        ])->save();
    }

    /**
     * Withdraw everything of theirs still waiting on somebody.
     *
     * An open request belonging to somebody who has left is a decision no
     * approver should be asked to take: approving leave for a person who is
     * not here settles nothing, and it sits in the inbox forever otherwise.
     */
    protected function cancelOpenRequests(User $staff): int
    {
        $now = Carbon::now();

        $open = [RequestStatus::Pending->value, RequestStatus::Returned->value];

        $leave = LeaveRequest::query()
            ->where('user_id', $staff->id)
            ->whereIn('status', $open)
            ->update(['status' => RequestStatus::Cancelled, 'decided_at' => $now]);

        $lateness = LatenessRequest::query()
            ->where('user_id', $staff->id)
            ->whereIn('status', $open)
            ->update(['status' => RequestStatus::Cancelled, 'decided_at' => $now]);

        return $leave + $lateness;
    }

    /**
     * Leave that this person had agreed to cover, running past their last day.
     *
     * Their colleague's leave is not cancelled over it: that request belongs
     * to somebody who is still here and may well have been approved already.
     * It is counted and handed back so whoever is doing the exit knows what
     * needs reassigning.
     *
     * @return int<0, max>
     */
    protected function coverLeftBehind(User $staff, Carbon $lastDay): int
    {
        return LeaveRequest::query()
            ->where('relief_officer_id', $staff->id)
            ->committed()
            ->where('end_date', '>=', $lastDay->toDateString())
            ->count();
    }
}
