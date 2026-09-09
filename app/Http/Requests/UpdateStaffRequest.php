<?php

namespace App\Http\Requests;

use App\Enums\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateStaffRequest extends FormRequest
{
    use ResolvesRole;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission(Permission::ManageStaff) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var User $staff */
        $staff = $this->route('staff');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:190', Rule::unique('users', 'email')->ignore($staff->id)],
            'employee_id' => ['nullable', 'string', 'max:40', Rule::unique('users', 'employee_id')->ignore($staff->id)],
            'phone' => ['nullable', 'string', 'max:30'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            // A team is only meaningful inside its department, so the pair has
            // to agree. The departments page is where a team is created.
            'team_id' => [
                'nullable',
                'integer',
                Rule::exists('teams', 'id')->where('department_id', $this->input('department_id')),
            ],
            'position' => ['nullable', 'string', 'max:80'],
            'hired_at' => ['nullable', 'date'],
            // A person may hold several roles. The two that come with people
            // attached are not grantable here: naming somebody a head of
            // department or a team lead also names who they are responsible
            // for, which is the departments page's job.
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => [
                'string',
                Rule::exists('roles', 'slug'),
                Rule::notIn(Role::assignedThroughDepartments()),
            ],
            // Everyone who works a shift belongs to a site. Admins run the
            // system rather than punch a clock, so theirs is optional.
            'location_id' => [
                Rule::requiredIf(fn (): bool => ! in_array(Role::SUPER_ADMIN, (array) $this->input('roles', []), true)),
                'nullable',
                'integer',
                Rule::exists('locations', 'id'),
            ],
            'password' => ['nullable', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'roles.required' => 'Pick at least one role.',
            'roles.*.exists' => 'Pick a role from the list.',
            'roles.*.not_in' => 'Heads of department and team leads are named on the departments page, so that the people they are responsible for are named at the same time.',
            'location_id.required' => 'Pick the work location this person clocks in at.',
            'team_id.exists' => 'That team is not in the department chosen.',
        ];
    }
}
