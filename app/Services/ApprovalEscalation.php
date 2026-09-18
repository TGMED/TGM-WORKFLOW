<?php

namespace App\Services;

use App\Contracts\Approvable;
use App\Enums\Permission;
use App\Enums\RequestModule;
use App\Enums\RequestStatus;
use App\Models\ApprovalSetting;
use App\Models\User;
use App\Notifications\ApprovalOverdue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Chases requests nobody has decided.
 *
 * A request left alone is not refused, it is forgotten, and from where the
 * requester sits the two look identical. Once a module's stretch has passed,
 * the people team hears about it, and so does whoever manages the approver it
 * is sitting with: the person best placed to ask why, and the one place the
 * reporting line earns its keep.
 *
 * Nobody is asked to decide anything here. The approvers themselves have
 * already been written to, more than once in most cases, and writing again
 * would be nagging rather than escalating.
 */
class ApprovalEscalation
{
    public function __construct(protected RequestNotifier $notifier) {}

    /**
     * Chase everything overdue across every module.
     *
     * @return int<0, max> Requests escalated.
     */
    public function run(?Carbon $now = null): int
    {
        $now ??= Carbon::now();

        $escalated = 0;

        foreach (RequestModule::cases() as $module) {
            $escalated += $this->runModule($module, $now);
        }

        return $escalated;
    }

    /**
     * @return int<0, max>
     */
    public function runModule(RequestModule $module, Carbon $now): int
    {
        $hours = ApprovalSetting::escalationHours($module);

        if ($hours === null) {
            return 0;
        }

        $cutoff = $now->copy()->subHours($hours);
        $escalated = 0;

        /** @var class-string<Approvable&Model> $model */
        $model = $module->model();

        $model::query()
            // Written out rather than through the model's own scope: the
            // module hands back a class name, and a scope cannot be seen
            // through one.
            ->where('status', RequestStatus::Pending->value)
            ->whereNull('escalated_at')
            // Counted from when the request was filed. A request sent back and
            // raised again keeps its original date on purpose: the person has
            // been waiting since they first asked, whatever the chain has done
            // with it since.
            ->where('created_at', '<=', $cutoff)
            ->with('user')
            ->chunkById(100, function (Collection $requests) use (&$escalated, $now, $hours): void {
                foreach ($requests as $request) {
                    // Every module's model is an Approvable; the chunk only
                    // knows it has models, so the check is made once here.
                    if (! $request instanceof Approvable) {
                        continue;
                    }

                    $this->escalate($request, $now, $hours);
                    $escalated++;
                }
            });

        return $escalated;
    }

    /**
     * @param  Approvable&Model  $request
     */
    protected function escalate(Approvable $request, Carbon $now, int $hours): void
    {
        $waitingOn = $this->notifier->waitingOn($request);

        $waited = (int) $request->raisedAt()?->diffInHours($now) ?: $hours;

        foreach ($this->recipients($request, $waitingOn) as $recipient) {
            $recipient->notify(new ApprovalOverdue($request, $waitingOn, $waited));
        }

        // Stamped whether or not there was anybody to write to. A request with
        // no approver left to chase is a different problem, and repeating the
        // search every hour will not solve it.
        $request->forceFill(['escalated_at' => $now])->save();
    }

    /**
     * The people team, plus the manager of everyone the request is sitting
     * with.
     *
     * The people team hears whatever else is true of them: they own the
     * process, and one of them being able to approve the request as well does
     * not make a stalled request any less their business. A manager hears only
     * about somebody else's delay, so anyone in the waiting set is dropped
     * from that side: writing to them there would be nagging an approver who
     * has already been asked directly, twice over.
     *
     * Nobody is written to twice, and nobody hears about their own request.
     *
     * @param  Approvable&Model  $request
     * @param  Collection<int, User>  $waitingOn
     * @return Collection<int, User>
     */
    protected function recipients(Approvable $request, Collection $waitingOn): Collection
    {
        $people = User::query()
            ->active()
            ->withPermission(Permission::ManageStaff)
            ->get();

        $managerIds = $waitingOn
            ->pluck('manager_id')
            ->filter()
            ->unique()
            ->all();

        if ($managerIds !== []) {
            $managers = User::query()
                ->active()
                ->whereKey($managerIds)
                ->get()
                ->reject(fn (User $manager): bool => $waitingOn->contains('id', $manager->id));

            $people = $people->concat($managers);
        }

        /** @var Collection<int, User> $recipients */
        $recipients = $people
            ->unique('id')
            ->reject(fn (User $person): bool => $person->id === $request->requester()->id)
            ->values();

        return $recipients;
    }

    /**
     * Requests of a module that are already overdue, for the settings page to
     * show what switching escalation on would catch.
     *
     * @param  Builder<Model>  $query
     */
    public function pendingLongerThan(Builder $query, int $hours, ?Carbon $now = null): int
    {
        return $query
            ->where('created_at', '<=', ($now ?? Carbon::now())->copy()->subHours($hours))
            ->count();
    }
}
