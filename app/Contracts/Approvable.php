<?php

namespace App\Contracts;

use App\Enums\ApprovalStage;
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
     * The approver who filed the request on the requester's behalf, when it
     * was not the requester who filed it.
     */
    public function filedBy(): ?User;

    /**
     * A short line naming the request, used in toasts and the inbox.
     */
    public function summary(): string;

    /**
     * The facts of the request, as ordered label => value pairs, for places
     * with room to lay them out rather than a single line. A viewer is named
     * where one is reading it, so their own part can be pointed out to them.
     *
     * @return array<string, string>
     */
    public function details(?User $viewer = null): array;

    /**
     * Where the request has got to, in a sentence. Addressed to the viewer
     * when it is their turn, so nobody has to work out whether that is them.
     */
    public function standing(?User $viewer = null): string;

    /**
     * What happens after the current turn, where there is anything left to
     * say. Null once the request has nowhere further to go.
     */
    public function nextStep(?User $viewer = null): ?string;

    /**
     * Decisions recorded so far, oldest first, across every round.
     *
     * @return Collection<int, Approval>
     */
    public function decisions(): Collection;

    /**
     * Which time round the approval chain this request is on. A request sent
     * back and raised again starts a fresh round, and the rounds before it
     * stop counting for anything but the trail.
     */
    public function currentRound(): int;

    /**
     * Decisions taken in the current round.
     *
     * @return Collection<int, Approval>
     */
    public function currentDecisions(): Collection;

    public function requestStatus(): RequestStatus;

    /**
     * The number of approvals this request was raised under. It is snapshotted
     * per request, so changing the setting cannot move the goalposts.
     */
    public function approvalsRequired(): int;

    public function approvalsGiven(): int;

    public function approvalsOutstanding(): int;

    /**
     * Whether this person is still expected to decide on the request, and it
     * is their turn to do so.
     */
    public function awaitsDecisionFrom(User $user): bool;

    /**
     * The stage this person would be deciding at.
     */
    public function approvalStageFor(User $user): ApprovalStage;

    /**
     * The status the request lands on when a decision at this stage is a
     * rejection.
     */
    public function statusAfterRejection(ApprovalStage $stage): RequestStatus;

    public function raisedAt(): ?CarbonInterface;
}
