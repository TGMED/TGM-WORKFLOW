<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Who reports to whom, drawn from the reporting line on each person's record.
 *
 * Open to everybody who signs in. A chart that only managers can see answers
 * the wrong question: the people who most need to know who to ask are the ones
 * who have just arrived.
 *
 * Drawn from `manager_id` rather than from who heads a department, because
 * those answer different questions, and where they disagree it is the
 * reporting line that is right.
 */
class OrganogramController extends Controller
{
    public function index(Request $request): Response
    {
        $canManage = $request->user()->hasPermission(Permission::ManageDepartments);

        $people = User::query()
            ->active()
            ->with(['department:id,name', 'team:id,name'])
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'department_id', 'team_id', 'manager_id', 'employee_id']);

        /** @var array<int, array<int, int>> $childrenOf id => the ids reporting to it */
        $childrenOf = [];

        foreach ($people as $person) {
            if ($person->manager_id !== null) {
                $childrenOf[$person->manager_id][] = $person->id;
            }
        }

        // Anybody whose manager is not on this list sits at the top of it:
        // that covers the people who genuinely report to nobody, and anybody
        // left pointing at somebody who has since left.
        $ids = $people->pluck('id')->all();

        $roots = $people->filter(
            fn (User $person): bool => $person->manager_id === null
                || ! in_array($person->manager_id, $ids, true),
        );

        return Inertia::render('Organogram', [
            'nodes' => $people
                ->map(fn (User $person): array => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'initials' => $person->initials,
                    'employee_id' => $person->employee_id,
                    'position' => $person->position,
                    'department' => $person->department?->name,
                    'team' => $person->team?->name,
                    'manager_id' => $person->manager_id,
                    'reports' => count($childrenOf[$person->id] ?? []),
                    // Everyone below them, however many rungs down, which is
                    // the figure people actually mean by "how big is your team".
                    'below' => $this->countBelow($person->id, $childrenOf),
                    'is_you' => $person->id === $request->user()->id,
                ])
                ->values(),
            'roots' => $roots->pluck('id')->values(),
            // Whether this reader may change the shape of the chart. Everyone
            // reads it; only the people team rearranges it.
            'can_manage' => $canManage,
            // The jobs the approval chain on a request actually reads, so the
            // one screen can answer both questions people bring to it. Sent
            // only to somebody who may change them.
            'units' => $canManage ? $this->units() : [],
            'assignable' => $canManage ? $this->assignable() : [],
            'totals' => [
                'people' => $people->count(),
                'roots' => $roots->count(),
                // Nobody's manager is nobody's problem until somebody looks
                // for them, so the number is said out loud.
                'unplaced' => $people->whereNull('manager_id')->count(),
            ],
            'you' => $request->user()->id,
        ]);
    }

    /**
     * The departments and their teams, with whoever is responsible for each.
     *
     * Kept apart from the chart itself because they are a different thing:
     * `manager_id` draws the picture, while a head and a lead are the jobs a
     * leave request is routed through. Somebody can hold one without the
     * other, and flattening them would hide exactly that.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function units(): array
    {
        return Department::query()
            ->with(['teams' => fn ($query) => $query->orderBy('name')])
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'head_user_id' => $department->head_user_id,
                'teams' => $department->teams
                    ->map(fn (Team $team): array => [
                        'id' => $team->id,
                        'name' => $team->name,
                        'lead_user_id' => $team->lead_user_id,
                    ])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * Everybody who can be named, for the pickers.
     *
     * @return array<int, array{value: int, label: string}>
     */
    protected function assignable(): array
    {
        return User::query()
            ->active()
            ->orderBy('name')
            ->get(['id', 'name', 'position'])
            ->map(fn (User $person): array => [
                'value' => $person->id,
                'label' => $person->position === null
                    ? $person->name
                    : "{$person->name} · {$person->position}",
            ])
            ->all();
    }

    /**
     * How many people sit below this one, all the way down.
     *
     * Walks breadth-first with a seen list, so a loop written straight into
     * the table cannot take the page down with it.
     *
     * @param  array<int, array<int, int>>  $childrenOf
     */
    protected function countBelow(int $id, array $childrenOf): int
    {
        $queue = [$id];
        $seen = [$id => true];
        $count = 0;

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($childrenOf[$current] ?? [] as $child) {
                if (isset($seen[$child])) {
                    continue;
                }

                $seen[$child] = true;
                $count++;
                $queue[] = $child;
            }
        }

        return $count;
    }
}
