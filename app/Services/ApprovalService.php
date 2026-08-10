<?php

namespace App\Services;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Enums\RequestStatus;
use App\Models\Approval;
use App\Models\User;
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

            Approval::query()->create([
                'approvable_type' => $request->getMorphClass(),
                'approvable_id' => $request->getKey(),
                'approver_id' => $approver->id,
                'step' => $request->decisions()->count() + 1,
                'decision' => $decision,
                'comment' => $comment,
                'decided_at' => $now,
            ]);

            $request->load('approvals');

            if ($decision === ApprovalDecision::Rejected) {
                $request->forceFill([
                    'status' => RequestStatus::Rejected,
                    'decided_at' => $now,
                ])->save();

                return true;
            }

            if ($request->approvalsOutstanding() === 0) {
                $request->forceFill([
                    'status' => RequestStatus::Approved,
                    'decided_at' => $now,
                ])->save();
            }

            return true;
        });
    }

    /**
     * How many open requests of a given type this person still has to decide
     * on. Drives the badge on the approvals nav item.
     *
     * @param  class-string<Approvable&Model>  $model
     */
    public function outstandingCount(string $model, User $approver): int
    {
        return $model::query()
            ->where('status', RequestStatus::Pending->value)
            ->where('user_id', '!=', $approver->id)
            ->whereDoesntHave(
                'approvals',
                fn ($query) => $query->where('approver_id', $approver->id),
            )
            ->count();
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
                'approver' => $approval->approver->name,
                'decision' => $approval->decision->value,
                'decision_label' => $approval->decision->label(),
                'comment' => $approval->comment,
                'decided_at' => $approval->decided_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
