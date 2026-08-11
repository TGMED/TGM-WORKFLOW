<?php

namespace App\Services;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Enums\RequestStatus;
use App\Models\Approval;
use App\Models\LatenessRequest;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ApprovalService
{
    /**
     * Record one approver's decision and settle the request if that decision
     * finished it. A rejection ends the request outright, however many
     * approvals were still outstanding.
     *
     * @param  Approvable&Model  $request
     */
    public function decide(
        Approvable $request,
        User $approver,
        ApprovalDecision $decision,
        ?string $comment = null,
    ): bool {
        return DB::transaction(function () use ($request, $approver, $decision, $comment): bool {
            // Two approvers can click at the same moment. Re-read the row
            // under a lock so only one of them can be the deciding vote.
            $request->newQuery()->whereKey($request->getKey())->lockForUpdate()->first();
            $request->refresh()->load('approvals');

            if (! $request->awaitsDecisionFrom($approver)) {
                return false;
            }

            $now = Carbon::now();
            $stage = $request->approvalStageFor($approver);

            Approval::query()->create([
                'approvable_type' => $request->getMorphClass(),
                'approvable_id' => $request->getKey(),
                'approver_id' => $approver->id,
                'step' => $request->currentDecisions()->count() + 1,
                'round' => $request->currentRound(),
                'stage' => $stage,
                'decision' => $decision,
                'comment' => $comment,
                'decided_at' => $now,
            ]);

            $request->load('approvals');

            if ($decision === ApprovalDecision::Rejected) {
                $request->forceFill([
                    'status' => $request->statusAfterRejection($stage),
                    'decided_at' => $now,
                ])->save();

                return true;
            }

            if ($stage->countsTowardsApproval() && $request->approvalsOutstanding() === 0) {
                $request->forceFill([
                    'status' => RequestStatus::Approved,
                    'decided_at' => $now,
                ])->save();
            }

            return true;
        });
    }

    /**
     * Open leave it is this person's turn to decide on. Whose turn it is
     * depends on how far the request has got, which no single query can
     * express, so the shortlist is narrowed in SQL and settled in PHP.
     *
     * @return Collection<int, LeaveRequest>
     */
    public function awaitingLeave(User $approver): Collection
    {
        return LeaveRequest::query()
            ->with('approvals')
            ->pending()
            ->where('user_id', '!=', $approver->id)
            // A decision from an earlier round is spent: a resubmission asks
            // the same people again, so only this round rules them out.
            ->whereDoesntHave(
                'approvals',
                fn (Builder $query) => $query->where('approver_id', $approver->id)
                    ->whereColumn('approvals.round', 'leave_requests.round'),
            )
            ->get()
            ->filter(fn (LeaveRequest $leave): bool => $leave->awaitsDecisionFrom($approver))
            ->values();
    }

    /**
     * @return Collection<int, LatenessRequest>
     */
    public function awaitingLateness(User $approver): Collection
    {
        return LatenessRequest::query()
            ->with('approvals')
            ->pending()
            ->where('user_id', '!=', $approver->id)
            ->whereDoesntHave(
                'approvals',
                fn (Builder $query) => $query->where('approver_id', $approver->id),
            )
            ->get()
            ->filter(fn (LatenessRequest $late): bool => $late->awaitsDecisionFrom($approver))
            ->values();
    }

    /**
     * Everything waiting on this person, for the badge on the nav item. Staff
     * without approval rights can still be sitting on a relief sign-off.
     */
    public function inboxCount(User $user): int
    {
        $count = $user->canApprove() || $user->hasReliefDuties()
            ? $this->awaitingLeave($user)->count()
            : 0;

        if ($user->canApprove()) {
            $count += $this->awaitingLateness($user)->count();
        }

        return $count;
    }

    /**
     * Decisions recorded against a request, oldest first, shaped for display.
     *
     * @param  Approvable&Model  $request
     * @return array<int, array<string, mixed>>
     */
    public function trail(Approvable $request): array
    {
        return $request->decisions()
            ->map(fn (Approval $approval): array => [
                'id' => $approval->id,
                'step' => $approval->step,
                'round' => $approval->round,
                'superseded' => $approval->round < $request->currentRound(),
                'approver' => $approval->approver->name,
                'decision' => $approval->decision->value,
                'decision_label' => $approval->decision->label(),
                'stage' => $approval->stage->value,
                'stage_label' => $approval->stage->label(),
                'comment' => $approval->comment,
                'decided_at' => $approval->decided_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
