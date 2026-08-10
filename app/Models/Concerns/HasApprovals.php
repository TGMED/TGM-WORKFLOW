<?php

namespace App\Models\Concerns;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Enums\RequestStatus;
use App\Models\Approval;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;

/**
 * Shared approval bookkeeping for leave and lateness requests.
 *
 * @phpstan-require-implements Approvable
 */
trait HasApprovals
{
    /**
     * @return MorphMany<Approval, $this>
     */
    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable');
    }

    /**
     * Decisions already recorded, oldest first.
     *
     * @return Collection<int, Approval>
     */
    public function decisions(): Collection
    {
        return $this->approvals->sortBy('step')->values();
    }

    public function requestStatus(): RequestStatus
    {
        return $this->status;
    }

    public function approvalsRequired(): int
    {
        return $this->approvals_required;
    }

    public function approvalsGiven(): int
    {
        return $this->approvals
            ->where('decision', ApprovalDecision::Approved)
            ->count();
    }

    public function approvalsOutstanding(): int
    {
        return max(0, $this->approvalsRequired() - $this->approvalsGiven());
    }

    public function raisedAt(): ?CarbonInterface
    {
        return $this->created_at;
    }

    public function wasDecidedBy(User $user): bool
    {
        return $this->approvals->contains('approver_id', $user->id);
    }

    /**
     * An approver may act while the request is open, has not already had their
     * say, and is not their own.
     */
    public function awaitsDecisionFrom(User $user): bool
    {
        return $this->requestStatus()->isOpen()
            && $user->canApprove()
            && $this->user_id !== $user->id
            && ! $this->wasDecidedBy($user);
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', RequestStatus::Pending->value);
    }
}
