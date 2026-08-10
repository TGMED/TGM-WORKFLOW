<?php

namespace App\Contracts;

use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\Approval;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * A request that collects approvals before it takes effect.
 *
 * The bookkeeping behind these methods is shared, and lives in
 * \App\Models\Concerns\HasApprovals.
 */
interface Approvable
{
    public function module(): RequestModule;

    /**
     * The person who raised the request.
     */
    public function requester(): User;

    /**
     * A short line naming the request, used in toasts and the inbox.
     */
    public function summary(): string;

    /**
     * Decisions recorded so far, oldest first.
     *
     * @return Collection<int, Approval>
     */
    public function decisions(): Collection;

    public function requestStatus(): RequestStatus;

    /**
     * The number of approvals this request was raised under. It is snapshotted
     * per request, so changing the setting cannot move the goalposts.
     */
    public function approvalsRequired(): int;

    public function approvalsGiven(): int;

    public function approvalsOutstanding(): int;

    /**
     * Whether this person is still expected to decide on the request.
     */
    public function awaitsDecisionFrom(User $user): bool;

    public function raisedAt(): ?CarbonInterface;
}
