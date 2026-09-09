<?php

namespace App\Models\Concerns;

use App\Contracts\Approvable;
use App\Enums\ApprovalDecision;
use App\Enums\ApprovalStage;
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
     * Every decision recorded, oldest first, earlier rounds included. This is
     * the audit trail, so nothing is dropped from it.
     *
     * @return Collection<int, Approval>
     */
    public function decisions(): Collection
    {
        return $this->approvals->sortBy([['round', 'asc'], ['step', 'asc']])->values();
    }

    /**
     * Which time round the chain this request is on. Only leave can be sent
     * back and raised again, so everything else stays on its first round.
     */
    public function currentRound(): int
    {
        return 1;
    }

    /**
     * Decisions taken this time round. Earlier rounds stay on the trail but no
     * longer gate anything: a resubmitted request asks its people afresh.
     *
     * @return Collection<int, Approval>
     */
    public function currentDecisions(): Collection
    {
        return $this->approvals
            ->where('round', $this->currentRound())
            ->sortBy('step')
            ->values();
    }

    public function requestStatus(): RequestStatus
    {
        return $this->status;
    }

    /**
     * Who filed this, when it was not the person it belongs to.
     */
    public function filedBy(): ?User
    {
        return $this->raised_by_id === null ? null : $this->raisedBy;
    }

    public function approvalsRequired(): int
    {
        return $this->approvals_required;
    }

    /**
     * Only approval-stage decisions count. A relief officer's sign-off gates
     * the run rather than standing in for one of the approvals required.
     */
    public function approvalsGiven(): int
    {
        return $this->currentDecisions()
            ->where('decision', ApprovalDecision::Approved)
            ->where('stage', ApprovalStage::Approval)
            ->count();
    }

    /**
     * The hat this person wears on this request. Only leave has a stage other
     * than the plain approval, and it says so by overriding this.
     */
    public function approvalStageFor(User $user): ApprovalStage
    {
        return ApprovalStage::Approval;
    }

    /**
     * Where a decline leaves the request. A relief officer sends it back for
     * the requester to redo; an approver ends it.
     */
    public function statusAfterRejection(ApprovalStage $stage): RequestStatus
    {
        return $stage === ApprovalStage::Relief
            ? RequestStatus::Returned
            : RequestStatus::Rejected;
    }

    /**
     * How many approvals the request still needs before it is granted.
     *
     * Named people are gates rather than entries in a tally: while somebody
     * the request has to pass still has to see it, it is not granted, however
     * many approvals have already been given. Without this, a module asking
     * for a single approval would let the team lead grant the leave and the
     * head of department would never be asked at all.
     */
    public function approvalsOutstanding(): int
    {
        $outstanding = max(0, $this->approvalsRequired() - $this->approvalsGiven());

        return $this->approvalGatesFinished() ? $outstanding : max(1, $outstanding);
    }

    /**
     * Whether everybody this request has to pass has had their say. The
     * reporting line for every module; leave adds the approver the requester
     * named on top of it.
     */
    protected function approvalGatesFinished(): bool
    {
        return $this->lineFinished();
    }

    /**
     * Name whoever a turn belongs to, in the second person when the person
     * reading is the one it is waiting on. `awaitsDecisionFrom` is the test
     * rather than a name match, so a relief officer and an approver are each
     * addressed at the point the request is actually theirs.
     */
    protected function decider(?User $viewer, ?User $named, string $fallback): string
    {
        if ($viewer !== null && $this->awaitsDecisionFrom($viewer)) {
            return 'you';
        }

        return $named->name ?? $fallback;
    }

    /**
     * Name a person on the request, pointing out to the reader where they are
     * the one named. This is the part they hold, which is not the same
     * question as whose turn it is now.
     */
    protected function name(?User $person, ?User $viewer, string $fallback): string
    {
        if ($person === null) {
            return $fallback;
        }

        return $viewer !== null && $viewer->id === $person->id
            ? $person->name.' (you)'
            : $person->name;
    }

    /**
     * Name whoever a turn is coming to. Unlike `decider` this is about a turn
     * that has not arrived, so it goes on who is named rather than on who the
     * request would take a decision from now.
     */
    protected function upNext(?User $viewer, ?User $named, string $fallback): string
    {
        if ($named === null) {
            return $fallback;
        }

        return $viewer !== null && $viewer->id === $named->id ? 'you' : $named->name;
    }

    /**
     * A closed request has nothing to wait on, and says so instead. Null while
     * it is still open, leaving the caller to describe whose turn it is.
     */
    protected function settledStanding(): ?string
    {
        if ($this->requestStatus()->isOpen()) {
            return null;
        }

        return 'This request is '.strtolower($this->requestStatus()->label()).'.';
    }

    /**
     * How the request reads on a details block, for the fields every module
     * shares.
     *
     * @return array<string, string>
     */
    protected function sharedDetails(): array
    {
        return [
            'Requester' => $this->requester()->name,
            'Status' => $this->requestStatus()->label(),
            'Filed' => $this->raisedAt()?->format('j M Y') ?? 'Not recorded',
        ];
    }

    public function raisedAt(): ?CarbonInterface
    {
        return $this->created_at;
    }

    public function wasDecidedBy(User $user): bool
    {
        return $this->currentDecisions()->contains('approver_id', $user->id);
    }

    /**
     * An approver may act while the request is open, has not already had their
     * say, and is not their own.
     *
     * The reporting line goes first where the request carries one: a team lead
     * and then a head of department are asked before the request is opened to
     * everybody who may approve. A request carrying neither — somebody in no
     * team and no department, or one filed before the line existed — behaves
     * exactly as it always did.
     */
    public function awaitsDecisionFrom(User $user): bool
    {
        $open = $this->requestStatus()->isOpen()
            && $user->canApprove()
            && $this->user_id !== $user->id
            && ! $this->wasDecidedBy($user);

        if (! $open) {
            return false;
        }

        if (($awaiting = $this->lineAwaiting()) !== null) {
            return $awaiting === $user->id;
        }

        // Past the reporting line, the request is open to anyone who approves
        // for the company. It is not open to somebody whose approval rights
        // come only from heading a department or leading a team: those cover
        // their own people, and this request is not one of theirs.
        return $user->approvesCompanyWide();
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', RequestStatus::Pending->value);
    }
}
