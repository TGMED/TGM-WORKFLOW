<?php

namespace App\Services;

use App\Enums\ExitReason;
use App\Enums\RequestStatus;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\CoverHasLeft;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
    public function __construct(protected DepartmentAssignment $assignment) {}

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
        /** @var array{0: StaffExitOutcome, 1: Collection<int, LeaveRequest>} $result */
        $result = DB::transaction(function () use ($staff, $reason, $lastDay, $note): array {
            $staff->forceFill([
                'is_active' => false,
                'deactivated_at' => Carbon::now(),
                'exit_reason' => $reason,
                'exit_date' => $lastDay,
                'exit_note' => $note,
            ])->save();

            $cancelled = $this->cancelOpenRequests($staff);

            // Whatever they were running goes back to the company. A vacant
            // department is a gap the people team can see and fill; one headed
            // by somebody who has gone is a stage of approval nobody can move.
            $vacated = $this->assignment->standDown($staff);

            $stranded = $this->releaseRequestsWaitingOnThem($staff);
            $cover = $this->coverLeftBehind($staff, $lastDay);

            // Their phone should stop buzzing about a job they no longer
            // hold. The rows go rather than being marked dead: a token is
            // worthless the moment it should not be written to.
            $staff->pushTokens()->delete();

            // Signing in is already barred, but a browser they left open is
            // still holding a live session until this clears it.
            DB::table('sessions')->where('user_id', $staff->id)->delete();

            return [
                new StaffExitOutcome($cancelled, $cover->count(), $vacated, $stranded),
                $cover,
            ];
        });
        [$outcome, $cover] = $result;

        // Told once the exit is safely committed, so nobody is written to
        // about a transaction that then rolled back.
        $this->tellThoseLosingCover($cover, $staff);

        return $outcome;
    }

    /**
     * Take their name off the open requests that would otherwise wait on it
     * forever.
     *
     * The reporting line and the named approver are stamped on a request when
     * it is filed, and only the person stamped can move it on. If that person
     * leaves, the request stops dead: nobody else is allowed to take it while
     * the row still names them. Clearing the stamp lets the stage fall away
     * and the request carry on to whoever is left.
     *
     * A stage they already ruled on is left alone. That decision is on the
     * trail and stays true whether or not they still work here.
     *
     * @return int<0, max> Requests that were stuck behind them.
     */
    protected function releaseRequestsWaitingOnThem(User $staff): int
    {
        $open = [RequestStatus::Pending->value, RequestStatus::Returned->value];
        $released = 0;

        $leave = LeaveRequest::query()
            ->with('approvals')
            ->whereIn('status', $open)
            ->where('user_id', '!=', $staff->id)
            ->where(fn ($q) => $q->where('team_lead_id', $staff->id)
                ->orWhere('head_id', $staff->id)
                ->orWhere('supervisor_id', $staff->id)
                ->orWhere('relief_officer_id', $staff->id))
            ->get();

        foreach ($leave as $request) {
            $clear = $this->lineStampsToClear($request, $staff);

            if ($request->supervisor_id === $staff->id && ! $request->supervisorDecided()) {
                $clear['supervisor_id'] = null;
            }

            // Cover they never got round to agreeing holds the request at its
            // first gate. The desk still needs covering, which is what the
            // requester is written to about; the request itself moves on.
            if ($request->relief_officer_id === $staff->id && ! $request->reliefAgreed()) {
                $clear['relief_officer_id'] = null;
            }

            if ($clear !== []) {
                $request->forceFill($clear)->save();
                $released++;
            }
        }

        $lateness = LatenessRequest::query()
            ->with('approvals')
            ->whereIn('status', $open)
            ->where('user_id', '!=', $staff->id)
            ->where(fn ($q) => $q->where('team_lead_id', $staff->id)->orWhere('head_id', $staff->id))
            ->get();

        foreach ($lateness as $request) {
            $clear = $this->lineStampsToClear($request, $staff);

            if ($clear !== []) {
                $request->forceFill($clear)->save();
                $released++;
            }
        }

        return $released;
    }

    /**
     * The reporting line stamps on one request that this person is holding up.
     *
     * @param  LeaveRequest|LatenessRequest  $request
     * @return array<string, null>
     */
    protected function lineStampsToClear($request, User $staff): array
    {
        $clear = [];

        if ($request->team_lead_id === $staff->id && ! $request->teamLeadDecided()) {
            $clear['team_lead_id'] = null;
        }

        if ($request->head_id === $staff->id && ! $request->headDecided()) {
            $clear['head_id'] = null;
        }

        return $clear;
    }

    /**
     * Write to the colleagues whose desk this person had agreed to cover.
     *
     * @param  Collection<int, LeaveRequest>  $cover
     */
    protected function tellThoseLosingCover(Collection $cover, User $staff): void
    {
        foreach ($cover as $leave) {
            $requester = $leave->user;

            if ($requester === null || ! $requester->is_active) {
                continue;
            }

            // Re-read after the exit: whether the request is still theirs to
            // edit depends on the stamps this exit has just cleared.
            $fresh = $leave->fresh()->load('leaveType', 'approvals');

            $requester->notify(new CoverHasLeft($fresh, $staff->name, $fresh->isEditable()));
        }
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
     * @return Collection<int, LeaveRequest>
     */
    protected function coverLeftBehind(User $staff, Carbon $lastDay): Collection
    {
        return LeaveRequest::query()
            ->with(['user', 'leaveType', 'approvals'])
            ->where('relief_officer_id', $staff->id)
            ->committed()
            ->where('end_date', '>=', $lastDay->toDateString())
            ->get();
    }
}
