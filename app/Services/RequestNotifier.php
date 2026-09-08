<?php

namespace App\Services;

use App\Contracts\Approvable;
use App\Enums\Permission;
use App\Enums\RequestModule;
use App\Models\Approval;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Notifications\ApprovalUpcoming;
use App\Notifications\RequestDecided;
use App\Notifications\RequestRaised;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Tells people what a request needs from them.
 *
 * Whose turn it is is the request's own business, so this asks the request
 * rather than working it out again: anyone the chain would accept a decision
 * from right now is someone worth writing to.
 */
class RequestNotifier
{
    /**
     * A request has just been raised, or raised again after being sent back.
     *
     * Everyone with a part in it hears: whoever it is sitting with, the person
     * it belongs to and whoever filed it for them, and the approver named to
     * rule on it later. Nobody is written to twice, however many of those
     * parts one person happens to be playing.
     *
     * @param  Approvable&Model  $request
     */
    public function raised(Approvable $request): void
    {
        $this->load($request);

        $told = $this->askThoseItWaitsOn($request);

        foreach ($this->requesterSide($request) as $person) {
            if (in_array($person->id, $told, true)) {
                continue;
            }

            $person->notify(new RequestRaised($request));
            $told[] = $person->id;
        }

        $this->warnApproverInWaiting($request, $told);
    }

    /**
     * Give the approver named on a leave request notice that it is coming.
     *
     * Their turn does not arrive until the desk is covered, so this asks
     * nothing of them. Anyone the request is already waiting on has had a
     * message that does ask something, and is left to it.
     *
     * @param  Approvable&Model  $request
     * @param  array<int, int>  $told
     */
    protected function warnApproverInWaiting(Approvable $request, array $told): void
    {
        if (! $request instanceof LeaveRequest) {
            return;
        }

        $supervisor = $request->supervisor;

        if ($supervisor === null || $request->reliefAgreed()) {
            return;
        }

        if (in_array($supervisor->id, $told, true)) {
            return;
        }

        $supervisor->notify(new ApprovalUpcoming($request));
    }

    /**
     * A decision has landed. The person whose request it is hears the result,
     * and whoever it moved on to hears that it is now theirs.
     *
     * @param  Approvable&Model  $request
     */
    public function decided(Approvable $request, Approval $approval): void
    {
        $approval->loadMissing('approver');
        $this->load($request);

        $told = $this->requesterSide($request);

        Notification::send($told, new RequestDecided($request, $approval));

        $this->askThoseItWaitsOn($request, $told->pluck('id')->all());
    }

    /**
     * Write to everyone the request is now sitting with.
     *
     * @param  Approvable&Model  $request
     * @param  array<int, int>  $skip
     * @return array<int, int> everyone written to, the skipped included
     */
    protected function askThoseItWaitsOn(Approvable $request, array $skip = []): array
    {
        $this->load($request);

        $told = $skip;

        foreach ($this->waitingOn($request) as $recipient) {
            if (in_array($recipient->id, $told, true)) {
                continue;
            }

            $recipient->notify(new ApprovalRequested($request, $request->approvalStageFor($recipient)));

            $told[] = $recipient->id;
        }

        return $told;
    }

    /**
     * Everyone who could take the next decision on this request.
     *
     * The candidates are narrowed cheaply and then put to the request itself,
     * which is the only thing that knows whether the relief officer has signed
     * off or the named supervisor has had their turn.
     *
     * @param  Approvable&Model  $request
     * @return Collection<int, User>
     */
    public function waitingOn(Approvable $request): Collection
    {
        return $this->candidates($request)
            ->filter(fn (User $user): bool => $request->awaitsDecisionFrom($user))
            ->values();
    }

    /**
     * @param  Approvable&Model  $request
     * @return Collection<int, User>
     */
    protected function candidates(Approvable $request): Collection
    {
        $approvers = User::query()
            ->active()
            ->withPermission(Permission::ApproveRequests)
            ->whereKeyNot($request->requester()->id)
            ->get();

        // Leave can also be sitting with a relief officer, who need not be an
        // approver at all.
        if ($request->module() === RequestModule::Leave) {
            /** @var LeaveRequest $request */
            $relief = $request->relief_officer_id === null ? null : $request->reliefOfficer;

            if ($relief !== null && ! $approvers->contains('id', $relief->id)) {
                return $approvers->push($relief)->values();
            }
        }

        return $approvers;
    }

    /**
     * The person the request belongs to, plus the approver who filed it for
     * them when that was somebody else. Both have a stake in the outcome.
     *
     * @param  Approvable&Model  $request
     * @return Collection<int, User>
     */
    protected function requesterSide(Approvable $request): Collection
    {
        $people = collect([$request->requester()]);
        $filer = $request->filedBy();

        if ($filer !== null && $filer->id !== $request->requester()->id) {
            $people->push($filer);
        }

        /** @var Collection<int, User> $people */
        return $people;
    }

    /**
     * The relations every message reads off the request.
     *
     * @param  Approvable&Model  $request
     */
    protected function load(Approvable $request): void
    {
        $request->loadMissing($request->module() === RequestModule::Leave
            ? ['user', 'raisedBy', 'leaveType', 'reliefOfficer', 'supervisor', 'approvals']
            : ['user', 'raisedBy', 'approvals']);
    }
}
