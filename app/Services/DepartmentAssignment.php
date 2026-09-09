<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Role;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AddedToDepartment;
use App\Notifications\NamedHeadOfDepartment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Who runs what, and who they run it for.
 *
 * Naming a head of department or a team lead does two things at once: it grants
 * the role, and it settles which people that role covers. Doing them apart is
 * how you end up with a team lead who leads nobody, so both happen here, in one
 * transaction, and nowhere else.
 *
 * The roles are only ever held for as long as the job is: dropping a head takes
 * the role back off them, unless they still head or lead something else.
 */
class DepartmentAssignment
{
    /**
     * Point a department at a head, and move the named people into it.
     *
     * @param  array<int, int>|null  $memberIds  Null leaves the membership alone.
     */
    public function setHead(Department $department, ?int $headId, ?array $memberIds = null): void
    {
        $arrivals = DB::transaction(function () use ($department, $headId, $memberIds): array {
            $previous = $department->head_user_id;

            $department->forceFill(['head_user_id' => $headId])->save();

            $arrived = $memberIds === null
                ? []
                : $this->syncMembers($department, $memberIds);

            // The head belongs to the department they run. Anything else makes
            // "the people under this head" a question with two answers.
            if ($headId !== null) {
                if (! in_array($headId, $arrived, true)
                    && ! $department->members()->whereKey($headId)->exists()) {
                    $arrived[] = $headId;
                }

                User::query()->whereKey($headId)->update(['department_id' => $department->id]);
            }

            $this->refreshRole($previous);
            $this->refreshRole($headId);

            return [
                // Somebody handed the department is told they run it, not that
                // they joined it; the one message covers both.
                'head' => $headId !== null && $headId !== $previous ? $headId : null,
                'members' => array_values(array_diff($arrived, [$headId])),
            ];
        });

        // Told once the move is safely committed, so nobody is written to
        // about a transaction that then rolled back.
        $this->announce($department, $arrivals);
    }

    /**
     * Point a team at a lead, and move the named people into it.
     *
     * @param  array<int, int>|null  $memberIds  Null leaves the membership alone.
     */
    public function setLead(Team $team, ?int $leadId, ?array $memberIds = null): void
    {
        DB::transaction(function () use ($team, $leadId, $memberIds): void {
            $previous = $team->lead_user_id;

            $team->forceFill(['lead_user_id' => $leadId])->save();

            if ($memberIds !== null) {
                $this->syncTeamMembers($team, $memberIds);
            }

            if ($leadId !== null) {
                User::query()->whereKey($leadId)->update([
                    'department_id' => $team->department_id,
                    'team_id' => $team->id,
                ]);
            }

            $this->refreshRole($previous);
            $this->refreshRole($leadId);
        });
    }

    /**
     * Exactly these people are in the department, and nobody else.
     *
     * Somebody moved out loses their team with it: a team belongs to one
     * department, so a member who leaves the department cannot stay in it.
     *
     * Returns the people who were not in it before, so they can be told and
     * everybody who was already there is left in peace.
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, int>
     */
    public function syncMembers(Department $department, array $memberIds): array
    {
        return DB::transaction(function () use ($department, $memberIds): array {
            $teamIds = $department->teams()->pluck('id');

            $already = $department->members()->pluck('users.id')->all();

            User::query()
                ->where('department_id', $department->id)
                ->whereKeyNot($memberIds)
                ->update(['department_id' => null, 'team_id' => null]);

            User::query()
                ->whereKey($memberIds)
                ->update(['department_id' => $department->id]);

            // Anyone who arrived from elsewhere carries no team of this
            // department's, and a team of their old one would be a lie.
            User::query()
                ->where('department_id', $department->id)
                ->whereNotNull('team_id')
                ->whereNotIn('team_id', $teamIds)
                ->update(['team_id' => null]);

            return array_values(array_diff($memberIds, $already));
        });
    }

    /**
     * Exactly these people are in the team. They are put into the team's
     * department at the same time, since a team member outside the department
     * is not something the rest of the app can read.
     *
     * @param  array<int, int>  $memberIds
     */
    public function syncTeamMembers(Team $team, array $memberIds): void
    {
        DB::transaction(function () use ($team, $memberIds): void {
            User::query()
                ->where('team_id', $team->id)
                ->whereKeyNot($memberIds)
                ->update(['team_id' => null]);

            User::query()
                ->whereKey($memberIds)
                ->update(['department_id' => $team->department_id, 'team_id' => $team->id]);
        });
    }

    /**
     * Everything a department has to let go of before it is removed: its head,
     * its teams and their leads, and the people who sat in it.
     */
    public function dissolve(Department $department): void
    {
        DB::transaction(function () use ($department): void {
            $affected = [$department->head_user_id, ...$department->teams()->pluck('lead_user_id')->all()];

            User::query()
                ->where('department_id', $department->id)
                ->update(['department_id' => null, 'team_id' => null]);

            $department->teams()->each(fn (Team $team) => $team->delete());
            $department->forceFill(['head_user_id' => null])->save();
            $department->delete();

            foreach ($affected as $userId) {
                $this->refreshRole($userId);
            }
        });
    }

    /**
     * A team going away leaves its members in the department, since that is
     * still true of them; only the team itself stops existing.
     */
    public function disband(Team $team): void
    {
        DB::transaction(function () use ($team): void {
            $lead = $team->lead_user_id;

            User::query()->where('team_id', $team->id)->update(['team_id' => null]);

            $team->forceFill(['lead_user_id' => null])->save();
            $team->delete();

            $this->refreshRole($lead);
        });
    }

    /**
     * Take somebody out of everything they run.
     *
     * A department headed by a person who has left is a stage of approval
     * nobody can move past, and the role would sit on a closed account for
     * good. The jobs are left vacant rather than handed on: who takes them
     * over is a decision for the people team, not for the exit.
     *
     * @return array<int, string> What they were running, for the record.
     */
    public function standDown(User $user): array
    {
        return DB::transaction(function () use ($user): array {
            $headed = Department::query()->where('head_user_id', $user->id)->pluck('name')->all();
            $led = Team::query()->where('lead_user_id', $user->id)->pluck('name')->all();

            Department::query()->where('head_user_id', $user->id)->update(['head_user_id' => null]);
            Team::query()->where('lead_user_id', $user->id)->update(['lead_user_id' => null]);

            $this->refreshRole($user->id);

            return [...$headed, ...$led];
        });
    }

    /**
     * Write to the people this move was about: whoever has just been handed
     * the department, and whoever has just been put in it.
     *
     * Which of them actually wants an email is the notification's own business
     * - it belongs to a topic people can switch off - so this only decides who
     * the move concerned.
     *
     * @param  array{head: int|null, members: array<int, int>}  $arrivals
     */
    protected function announce(Department $department, array $arrivals): void
    {
        $department->refresh()->load('head');

        if ($arrivals['head'] !== null) {
            $head = User::query()->find($arrivals['head']);

            if ($head !== null) {
                $head->notify(new NamedHeadOfDepartment($department));
            }
        }

        if ($arrivals['members'] === []) {
            return;
        }

        Notification::send(
            User::query()->whereKey($arrivals['members'])->get(),
            new AddedToDepartment($department),
        );
    }

    /**
     * Give somebody the head or lead role if they run something, and take it
     * back if they no longer do.
     *
     * Asked of the record rather than tracked as a count, so a role can never
     * drift away from the job it describes.
     */
    protected function refreshRole(?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $user = User::query()->find($userId);

        if ($user === null) {
            return;
        }

        $wanted = [];

        if (Department::query()->where('head_user_id', $userId)->exists()) {
            $wanted[] = Role::HEAD_OF_DEPARTMENT;
        }

        if (Team::query()->where('lead_user_id', $userId)->exists()) {
            $wanted[] = Role::TEAM_LEAD;
        }

        $keep = $user->roles()
            ->whereNotIn('slug', Role::assignedThroughDepartments())
            ->pluck('roles.id')
            ->all();

        $granted = Role::query()->whereIn('slug', $wanted)->pluck('id')->all();

        $roles = [...$keep, ...$granted];

        // Never leave somebody with no role at all: losing a headship should
        // put them back among the staff, not lock them out of the app.
        if ($roles === []) {
            $roles = [Role::idFor(Role::STAFF)];
        }

        $user->roles()->sync($roles);
    }
}
