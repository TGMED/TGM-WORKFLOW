<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\DepartmentRequest;
use App\Models\Department;
use App\Models\Team;
use App\Models\User;
use App\Services\DepartmentAssignment;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * How the company is arranged, and who is responsible for each part of it.
 *
 * This is the only page that grants the head of department and team lead
 * roles, because it is the only page where the people those roles cover are
 * named at the same time.
 */
class DepartmentController extends Controller
{
    public function __construct(protected DepartmentAssignment $assignment) {}

    public function index(): Response
    {
        return Inertia::render('admin/Departments', [
            'departments' => $this->departments(),
            'staff' => $this->assignableStaff(),
            'unassigned' => User::query()
                ->active()
                ->clocksIn()
                ->whereNull('department_id')
                ->count(),
        ]);
    }

    public function store(DepartmentRequest $request): RedirectResponse
    {
        $department = Department::query()->create($request->payload());

        $this->assignment->setHead(
            $department,
            $request->integer('head_user_id') ?: null,
            $request->members(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$department->name} has been created.",
        ]);
    }

    public function update(DepartmentRequest $request, Department $department): RedirectResponse
    {
        $department->update($request->payload());

        $this->assignment->setHead(
            $department,
            $request->integer('head_user_id') ?: null,
            $request->members(),
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$department->name} has been updated.",
        ]);
    }

    public function destroy(Department $department): RedirectResponse
    {
        $name = $department->name;

        $this->assignment->dissolve($department);

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$name} has been removed. Its people are no longer in a department.",
        ]);
    }

    /**
     * Every department, with its head, its teams and who sits in each.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function departments(): array
    {
        return Department::query()
            ->with([
                'head:id,name,position',
                'teams' => fn ($query) => $query->orderBy('name'),
                'teams.lead:id,name',
                'teams.members' => fn ($query) => $query->active()->orderBy('name'),
                'members' => fn ($query) => $query->active()->orderBy('name'),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Department $department): array => [
                'id' => $department->id,
                'name' => $department->name,
                'slug' => $department->slug,
                'description' => $department->description,
                'is_active' => $department->is_active,
                'head' => $department->head === null ? null : [
                    'id' => $department->head->id,
                    'name' => $department->head->name,
                    'position' => $department->head->position,
                ],
                'head_user_id' => $department->head_user_id,
                'member_ids' => $department->members->pluck('id')->all(),
                'members' => $department->members
                    ->map(fn (User $member): array => $this->person($member))
                    ->all(),
                'teams' => $department->teams
                    ->map(fn (Team $team): array => [
                        'id' => $team->id,
                        'name' => $team->name,
                        'lead' => $team->lead === null ? null : [
                            'id' => $team->lead->id,
                            'name' => $team->lead->name,
                        ],
                        'lead_user_id' => $team->lead_user_id,
                        'member_ids' => $team->members->pluck('id')->all(),
                        'members' => $team->members
                            ->map(fn (User $member): array => $this->person($member))
                            ->all(),
                    ])
                    ->all(),
            ])
            ->all();
    }

    /**
     * Everyone who can be put in a department, carrying where they already
     * sit so the page can warn that choosing them moves them.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function assignableStaff(): array
    {
        return User::query()
            ->active()
            ->clocksIn()
            ->with('department:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'position', 'department_id', 'team_id'])
            ->map(fn (User $user): array => [
                ...$this->person($user),
                'department' => $user->department?->name,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function person(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'position' => $user->position,
            'department_id' => $user->department_id,
            'team_id' => $user->team_id,
        ];
    }
}
