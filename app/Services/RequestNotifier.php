<?php

namespace App\Services;

use App\Contracts\Approvable;
use App\Enums\RequestModule;
use App\Models\Approval;
use App\Models\LeaveRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\ApprovalRequested;
use App\Notifications\RequestDecided;
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
     * @param  Approvable&Model  $request
     */
    public function raised(Approvable $request): void
    {
        $this->askThoseItWaitsOn($request);
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
     */
    protected function askThoseItWaitsOn(Approvable $request, array $skip = []): void
    {
        $this->load($request);

        foreach ($this->waitingOn($request) as $recipient) {
            if (in_array($recipient->id, $skip, true)) {
                continue;
            }

            $recipient->notify(new ApprovalRequested($request, $request->approvalStageFor($recipient)));
        }
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
            ->withRole(Role::APPROVER, Role::SUPER_ADMIN)
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
