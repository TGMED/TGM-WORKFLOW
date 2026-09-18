<?php

namespace App\Http\Controllers;

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
