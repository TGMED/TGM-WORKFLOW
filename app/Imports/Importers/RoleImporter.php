<?php

namespace App\Imports\Importers;

use App\Enums\ImportDuplicates;
use App\Enums\Permission;
use App\Imports\BaseImporter;
use App\Imports\ImportColumn;
use App\Imports\ImportResult;
use App\Imports\ImportRow;
use App\Models\Role;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Roles and what each one may do.
 *
 * Staff rows name a role by slug, so this runs before them. Super admins are
 * left alone by design: they hold the whole catalogue implicitly, and a file
 * that could take a permission off them is a file that could lock the last
 * administrator out of the system.
 */
class RoleImporter extends BaseImporter
{
    public function key(): string
    {
        return 'roles';
    }

    public function label(): string
    {
        return 'Roles';
    }

    public function description(): string
    {
        return 'Roles and the permissions each one holds.';
    }

    public function permission(): Permission
    {
        return Permission::ManageRoles;
    }

    public function matchedOn(): string
    {
        return 'the role slug';
    }

    /**
     * @return array<int, string>
     */
    public function notes(): array
    {
        return [
            'The super_admin role is skipped wherever it appears. It holds every permission implicitly, and letting a file take one away would be a way to lock the last administrator out.',
            'The permissions column replaces the whole list for that role, it does not add to it. Leave it out of the file to change a role\'s name without touching what it may do.',
            'The three roles the app ships with — super_admin, approver and staff — can be renamed and re-permissioned, but not created or removed here.',
            'Valid permissions are: '.implode(', ', Permission::values()).'.',
        ];
    }

    /**
     * @return array<int, ImportColumn>
     */
    public function columns(): array
    {
        return [
            ImportColumn::make(
                'slug',
                'The role\'s short name, which staff rows point at. Left blank it is worked out from the name; give it when you are updating a role that already exists.',
                'shift_supervisor',
                format: 'Lowercase letters, numbers and underscores',
            ),
            ImportColumn::required('name', 'What the role is called on screen.', 'Shift Supervisor'),
            ImportColumn::make('description', 'A sentence on who this role is for.', 'Runs a shift and decides on requests for their team.'),
            ImportColumn::make(
                'permissions',
                'Everything this role may do, separated by semicolons. Replaces the role\'s whole list.',
                'admin.dashboard;requests.approve;attendance.report',
                format: 'Semicolon-separated permission names',
                accepts: Permission::values(),
            ),
        ];
    }

    public function import(ImportRow $row, ImportDuplicates $duplicates, ImportResult $result): void
    {
        $name = $row->string('name');
        $slug = $row->string('slug') ?? ($name === null ? null : Str::snake(Str::lower($name)));

        if ($slug === Role::SUPER_ADMIN) {
            $result->skipped();

            return;
        }

        $existing = $slug === null ? null : Role::query()->where('slug', $slug)->first();

        if ($existing !== null && $duplicates === ImportDuplicates::Skip) {
            $result->skipped();

            return;
        }

        if ($existing !== null && $duplicates === ImportDuplicates::Reject) {
            $this->reject("There is already a role with the slug {$slug}.");
        }

        $data = $this->validate(
            [
                'slug' => $slug,
                'name' => $name,
                'description' => $row->string('description'),
                'permissions' => $row->has('permissions') ? $row->list('permissions') : null,
            ],
            [
                'slug' => [
                    'required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/',
                    Rule::unique('roles', 'slug')->ignore($existing?->id),
                ],
                'name' => ['required', 'string', 'max:60', Rule::unique('roles', 'name')->ignore($existing?->id)],
                'description' => ['nullable', 'string', 'max:255'],
                'permissions' => ['nullable', 'array'],
                'permissions.*' => [Rule::in(Permission::values())],
            ],
            [
                'slug.regex' => 'A slug is lowercase letters, numbers and underscores, starting with a letter.',
                'slug.unique' => 'Another role already uses that slug.',
                'name.unique' => 'Another role already has that name.',
                'permissions.*.in' => 'That is not a permission this system has. Check the reference file for the list.',
            ],
        );

        $role = $existing;

        if ($role === null) {
            $role = Role::query()->create([
                'slug' => $data['slug'],
                'name' => $data['name'],
                'description' => $data['description'],
                // Only the three seeded roles are wired into middleware.
                // Anything arriving by import is an ordinary role and stays
                // deletable from the roles page.
                'is_system' => false,
            ]);

            $result->created();
        } else {
            $role->update($this->present([
                'name' => $data['name'],
                'description' => $data['description'],
            ]));

            $result->updated();
        }

        if ($data['permissions'] !== null) {
            $role->syncPermissions($data['permissions']);
        }
    }
}
