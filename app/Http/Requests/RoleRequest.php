<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManageRoles) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('roles', 'name')->ignore($this->role()?->id),
            ],
            'description' => ['nullable', 'string', 'max:255'],

            // An empty list is a real answer: a role that may do nothing but
            // sign in and raise its own requests.
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Permission::values())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'There is already a role with that name.',
            'permissions.*.in' => 'That is not a permission this system has.',
        ];
    }

    /**
     * @return array<int, string>
     */
    public function permissions(): array
    {
        /** @var array<int, string> $permissions */
        $permissions = $this->validated('permissions', []);

        return $permissions;
    }

    /**
     * The slug is derived once, on creation, and then left alone: renaming a
     * role must not orphan the middleware or the seed data keyed on it.
     */
    public function slug(): string
    {
        $base = Str::slug($this->string('name')->toString(), '_');
        $slug = $base === '' ? 'role' : $base;
        $candidate = $slug;
        $suffix = 2;

        while (Role::query()->where('slug', $candidate)->exists()) {
            $candidate = "{$slug}_{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    protected function role(): ?Role
    {
        /** @var Role|null $role */
        $role = $this->route('role');

        return $role;
    }
}
