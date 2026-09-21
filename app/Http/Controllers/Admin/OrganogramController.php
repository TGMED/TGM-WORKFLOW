<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrganogramAssignmentRequest;
use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use App\Services\DepartmentAssignment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Changing the shape of the company from the chart itself.
 *
 * Three different things are set here, and they are not interchangeable:
 *
 *  - `manager_id` is the reporting line, and it is what the chart draws.
 *  - A department's head and a team's lead are jobs, and they are what the
 *    approval chain on a request actually reads.
 *
 * Somebody can have a manager without heading anything, and can head a
 * department without being anyone's manager on the chart. Both are set from
 * here so that the one screen answers the one question people come to it with,
 * but they stay separate fields because they answer different questions.
 *
 * Heads and leads go through DepartmentAssignment rather than being written
 * straight to the column: naming one grants the role and settles who it covers,
 * and doing that in two places is how the two drift apart.
 */
class OrganogramController extends Controller
{
    public function __construct(protected DepartmentAssignment $assignment) {}

    /**
     * Put somebody under a manager, or take them out from under one.
     */
    public function manager(OrganogramAssignmentRequest $request, User $user): RedirectResponse
    {
        $managerId = $request->managerId();

        $user->forceFill(['manager_id' => $managerId])->save();

        if ($managerId === null) {
            return back()->with('toast', [
                'type' => 'success',
                'message' => "{$user->name} now reports to nobody on the chart.",
            ]);
        }

        $manager = User::query()->findOrFail($managerId);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$user->name} now reports to {$manager->name}.",
        ]);
    }

    /**
     * Name who heads a department, or leave it vacant.
     *
     * The membership is left alone: this screen is about who is responsible
     * for a unit, not about who is in it, and that is the departments page.
     */
    public function head(Request $request, Department $department): RedirectResponse
    {
        $this->authoriseChanges($request);

        $headId = $this->person($request, 'head_user_id');

        $this->assignment->setHead($department, $headId);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $headId === null
                ? "{$department->name} has no head for now. Leave from that department goes straight to the people team."
                : "{$department->name} is now headed by ".User::query()->findOrFail($headId)->name.'.',
        ]);
    }

    public function lead(Request $request, Team $team): RedirectResponse
    {
        $this->authoriseChanges($request);

        $leadId = $this->person($request, 'lead_user_id');

        $this->assignment->setLead($team, $leadId);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $leadId === null
                ? "{$team->name} has no lead for now."
                : "{$team->name} is now led by ".User::query()->findOrFail($leadId)->name.'.',
        ]);
    }

    /**
     * The routes carry the permission too. This is the second lock, for the
     * same reason the departments page has one: these three calls change who
     * may approve what, and a route group is one edit away from not covering
     * them any more.
     */
    protected function authoriseChanges(Request $request): void
    {
        abort_unless(
            $request->user()?->hasPermission(Permission::ManageDepartments) === true,
            403,
        );
    }

    /**
     * A named person, or null where the job is being left vacant.
     */
    protected function person(Request $request, string $field): ?int
    {
        $validated = $request->validate([
            $field => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $id = $validated[$field] ?? null;

        return $id === null || $id === '' ? null : (int) $id;
    }
}
