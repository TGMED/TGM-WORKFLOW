<?php

namespace App\Services;

use App\Enums\Permission;
use App\Models\StaffAction;
use App\Models\User;
use App\Notifications\QueryAnswered;
use App\Notifications\StaffActionIssued;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

/**
 * Who hears about a query, warning or confirmation: the person it is about,
 * the head of their department, and the people team.
 */
class ConductNotifier
{
    public function issued(StaffAction $action): void
    {
        $action->loadMissing(['subject.department.head', 'issuedBy', 'offence']);

        $recipients = $this->accountable($action)->push($action->subject);

        Notification::send($recipients->unique('id')->values(), new StaffActionIssued($action));
    }

    public function answered(StaffAction $action): void
    {
        $action->loadMissing(['subject.department.head', 'issuedBy']);

        $recipients = $this->accountable($action);

        if ($action->issuedBy !== null && $action->issuedBy->is_active) {
            $recipients->push($action->issuedBy);
        }

        $recipients = $recipients
            ->reject(fn (User $user): bool => $user->id === $action->subject_user_id)
            ->unique('id')
            ->values();

        Notification::send($recipients, new QueryAnswered($action));
    }

    /**
     * The head of the person's department and the people team, never the
     * person themselves.
     *
     * @return Collection<int, User>
     */
    protected function accountable(StaffAction $action): Collection
    {
        $people = User::query()
            ->active()
            ->withPermission(Permission::ManageStaff)
            ->get();

        $head = $action->subject->department?->head;

        if ($head !== null && $head->is_active) {
            $people->push($head);
        }

        return $people
            ->reject(fn (User $user): bool => $user->id === $action->subject_user_id)
            ->values()
            ->toBase();
    }
}
