<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\RoleRequest;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Roles', [
            'roles' => $this->roles(),
            // The catalogue is code, so it is sent rather than stored: the
            // page can only ever offer permissions something actually checks.
            'catalogue' => Permission::catalogue(),
        ]);
    }

    public function store(RoleRequest $request): RedirectResponse
    {
        $role = Role::query()->create([
            'slug' => $request->slug(),
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
            'is_system' => false,
        ]);

        $role->syncPermissions($request->permissions());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$role->name} has been added. Assign it to staff from the staff page.",
        ]);
    }

    /**
     * Rename a role and set what it may do. The slug stays as it was: the
     * middleware and the seed data are keyed on it, and renaming a role should
     * not quietly move those.
     */
    public function update(RoleRequest $request, Role $role): RedirectResponse
    {
        $role->update([
            'name' => $request->string('name')->toString(),
            'description' => $request->input('description'),
        ]);

        if ($role->slug === Role::SUPER_ADMIN) {
            return back()->with('toast', [
                'type' => 'success',
                'message' => "{$role->name} updated. Super admins hold every permission and cannot be narrowed.",
            ]);
        }

        $role->syncPermissions($request->permissions());

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$role->name} updated.",
        ]);
    }

    /**
     * Drop a role nobody holds. System roles stay: the app authenticates
     * against them, and a database without them cannot sign anyone in.
     */
    public function destroy(Role $role): RedirectResponse
    {
        if ($role->is_system) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => "{$role->name} is a system role and cannot be deleted.",
            ]);
        }

        $holders = $role->users()->count();

        if ($holders > 0) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => "{$holders} member(s) of staff still hold {$role->name}. Move them to another role first.",
            ]);
        }

        $name = $role->name;

        $role->delete();

        return back()->with('toast', [
            'type' => 'success',
            'message' => "{$name} has been deleted.",
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function roles(): array
    {
        return Role::query()
            ->with('rolePermissions')
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role): array => [
                'id' => $role->id,
                'slug' => $role->slug,
                'name' => $role->name,
                'description' => $role->description,
                'is_system' => $role->is_system,
                // Super admins hold the catalogue implicitly rather than by
                // stored grants, so the page shows the boxes ticked and locked.
                'holds_everything' => $role->slug === Role::SUPER_ADMIN,
                'permissions' => array_map(
                    fn (Permission $permission): string => $permission->value,
                    $role->permissions(),
                ),
                'users_count' => $role->users_count,
            ])
            ->all();
    }
}
