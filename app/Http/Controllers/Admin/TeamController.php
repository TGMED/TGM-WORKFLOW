<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\TeamRequest;
use App\Models\Department;
use App\Models\Team;
use App\Services\DepartmentAssignment;
use Illuminate\Http\RedirectResponse;

/**
 * Teams inside a department. Nested under the department rather than standing
 * on their own, because a team without one is not a thing this app has.
 */
class TeamController extends Controller
{
    public function __construct(protected DepartmentAssignment $assignment) {}

    public function store(TeamRequest $request, Department $department): RedirectResponse
    {
        $team = $department->teams()->create([
            'name' => $request->string('name')->toString(),
        ]);

        $this->assignment->setLead(
            $team,
            $request->integer('lead_user_id') ?: null,
            $request->members(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$team->name} has been created in {$department->name}.",
        ]);
    }

    public function update(TeamRequest $request, Team $team): RedirectResponse
    {
        $team->update(['name' => $request->string('name')->toString()]);

        $this->assignment->setLead(
            $team,
            $request->integer('lead_user_id') ?: null,
            $request->members(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$team->name} has been updated.",
        ]);
    }

    public function destroy(Team $team): RedirectResponse
    {
        $name = $team->name;

        $this->assignment->disband($team);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$name} has been disbanded. Its people stay in the department.",
        ]);
    }
}
