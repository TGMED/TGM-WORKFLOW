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
            'department' => ['nullable', 'string', 'max:80'],
            'position' => ['nullable', 'string', 'max:80'],
            'hired_at' => ['nullable', 'date'],
            'role' => ['required', 'string', Rule::exists('roles', 'slug')],
            // Everyone who works a shift belongs to a site. Admins run the
            // system rather than punch a clock, so theirs is optional.
            'location_id' => [
                Rule::requiredIf(fn (): bool => $this->input('role') !== Role::SUPER_ADMIN),
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
            'role.exists' => 'Pick a role from the list.',
            'location_id.required' => 'Pick the work location this person clocks in at.',
        ];
    }
}
